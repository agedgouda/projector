<?php

use App\Jobs\AssembleBrowserRecording;
use App\Jobs\TranscribeRecording;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TusUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->user = User::factory()->withoutTwoFactor()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Recording Project', 'client_id' => $this->client->id]);

    $this->actingAs($this->user)->withSession(['active_org_id' => $this->org->id]);
});

/**
 * @param  array<string, mixed>  $metadata
 */
function recoveryRecording(Project $project, array $metadata = [], bool $withAudio = true, string $content = ''): Document
{
    $recording = $project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording — 10/13/2026 4:13pm',
        'content' => $content,
        'processed_at' => now(),
        'metadata' => array_merge(['recording_source' => 'browser_capture', 'audio_status' => 'pending'], $metadata),
    ]);

    if ($withAudio) {
        $dataSize = 100;
        $wav = 'RIFF'.pack('V', 36 + $dataSize).'WAVE'.'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8).'data'.pack('V', $dataSize)
            .str_repeat("\x00", $dataSize);
        $recording->addMedia(UploadedFile::fake()->createWithContent('meeting.wav', $wav))->toMediaCollection('recording');
    }

    return $recording;
}

function recoveryChunk(Document $recording, User $user, int $index, string $content): TusUpload
{
    $path = 'tus-uploads/'.Str::orderedUuid().'.partial';
    Storage::disk('local')->put($path, $content);

    return TusUpload::create([
        'project_id' => $recording->project_id,
        'creator_id' => $user->id,
        'document_id' => $recording->id,
        'kind' => 'partial',
        'upload_length' => strlen($content),
        'upload_offset' => strlen($content),
        'storage_path' => $path,
        'metadata' => ['filename' => "chunk-{$index}.webm", 'filetype' => 'audio/webm'],
        'completed_at' => now(),
        'claimed_at' => now(),
    ]);
}

function showDocument(Project $project, Document $document): \Illuminate\Testing\TestResponse
{
    return test()->get(route('projects.documents.show', ['project' => $project, 'document' => $document]));
}

it('shows the problem on a recording whose transcription failed', function () {
    $recording = recoveryRecording($this->project, ['transcription_error' => 'Transcription failed for an unknown reason.']);

    showDocument($this->project, $recording)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('recordingProblem.document_id', $recording->id)
        ->where('recordingProblem.reason', 'Transcription failed for an unknown reason.')
        ->where('recordingProblem.has_audio', true)
        ->where('recordingProblem.can_retry', true));
});

it('shows the recording\'s problem on its Meeting Notes page too', function () {
    $recording = recoveryRecording($this->project, ['transcription_error' => 'No speech detected in the recording.']);
    $notes = $this->project->documents()->create([
        'type' => config('workflow.action_items_key'),
        'name' => $recording->name,
        'content' => '',
        'parent_id' => $recording->id,
        'metadata' => ['recording_source' => 'browser_capture'],
    ]);

    showDocument($this->project, $notes)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('recordingProblem.document_id', $recording->id));
});

it('treats a recording with no transcript after an hour as stuck', function () {
    $recording = recoveryRecording($this->project);
    $recording->forceFill(['created_at' => now()->subMinutes(90)])->saveQuietly();

    showDocument($this->project, $recording)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('recordingProblem.has_audio', true));
});

it('shows nothing for a recording that is fine', function (array $metadata, string $content, int $ageMinutes) {
    $recording = recoveryRecording($this->project, $metadata, content: $content);
    $recording->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->saveQuietly();

    showDocument($this->project, $recording)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('recordingProblem', null));
})->with([
    'transcribed' => [[], 'The transcript.', 600],
    'still transcribing' => [[], '', 10],
    'retried a moment ago' => [['retried_at' => now()->subMinutes(5)->toIso8601String()], '', 600],
]);

it('hides the problem from people who can\'t act on it', function () {
    $member = User::factory()->withoutTwoFactor()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);
    $member->clients()->attach($this->client->id);
    $recording = recoveryRecording($this->project, ['transcription_error' => 'Transcription failed.']);

    $this->actingAs($member);

    showDocument($this->project, $recording)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('recordingProblem', null));
    // The app answers authorization failures with a 404 (see bootstrap/app.php).
    $this->post(route('projects.documents.recording.retry', ['project' => $this->project, 'document' => $recording]))
        ->assertNotFound();
    $this->get(route('projects.documents.recording.download', ['project' => $this->project, 'document' => $recording]))
        ->assertNotFound();
    Queue::assertNothingPushed();
});

it('can\'t retry a recording that is too large, but can still download it', function () {
    $recording = recoveryRecording($this->project, ['transcription_error' => 'Too large.', 'audio_too_large' => true], withAudio: false);
    recoveryChunk($recording, $this->user, 0, 'audio');

    showDocument($this->project, $recording)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('recordingProblem.can_retry', false)
        ->where('recordingProblem.has_audio', true));
});

it('sends attached audio back for transcription on Try Again', function () {
    $recording = recoveryRecording($this->project, ['transcription_error' => 'Failed.', 'transcription_id' => 'old-id']);

    $this->post(route('projects.documents.recording.retry', ['project' => $this->project, 'document' => $recording]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $metadata = $recording->fresh()->metadata;
    expect($metadata)->not->toHaveKey('transcription_error');
    expect($metadata)->not->toHaveKey('transcription_id');
    expect($metadata)->toHaveKey('retried_at');
    Queue::assertPushed(TranscribeRecording::class, fn ($job) => $job->document->is($recording));

    showDocument($this->project, $recording)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('recordingProblem', null));
});

it('reassembles audio that is still in pieces on Try Again', function () {
    $recording = recoveryRecording($this->project, ['transcription_error' => 'Failed.'], withAudio: false);
    recoveryChunk($recording, $this->user, 0, 'audio');

    $this->post(route('projects.documents.recording.retry', ['project' => $this->project, 'document' => $recording]))
        ->assertSessionHas('success');

    Queue::assertPushed(AssembleBrowserRecording::class, fn ($job) => $job->document->is($recording));
    Queue::assertNotPushed(TranscribeRecording::class);
});

it('says so when there is no audio left to try again with', function () {
    $recording = recoveryRecording($this->project, ['transcription_error' => 'Failed.'], withAudio: false);

    $this->post(route('projects.documents.recording.retry', ['project' => $this->project, 'document' => $recording]))
        ->assertSessionHas('error');

    Queue::assertNothingPushed();
});

it('downloads the attached audio', function () {
    $recording = recoveryRecording($this->project, ['transcription_error' => 'Failed.']);

    $this->get(route('projects.documents.recording.download', ['project' => $this->project, 'document' => $recording]))
        ->assertOk()
        ->assertDownload('meeting.wav');
});

it('downloads audio that is still in pieces, joined in recording order', function () {
    $recording = recoveryRecording($this->project, ['transcription_error' => 'Failed.'], withAudio: false);
    recoveryChunk($recording, $this->user, 1, 'BBB');
    recoveryChunk($recording, $this->user, 0, 'AAA');

    $response = $this->get(route('projects.documents.recording.download', ['project' => $this->project, 'document' => $recording]));

    $response->assertOk();
    expect($response->streamedContent())->toBe('AAABBB');
});

it('has nothing to download when no audio was saved', function () {
    $recording = recoveryRecording($this->project, ['transcription_error' => 'Failed.'], withAudio: false);

    $this->get(route('projects.documents.recording.download', ['project' => $this->project, 'document' => $recording]))
        ->assertNotFound();
});

it('ignores documents that aren\'t recordings', function () {
    $document = $this->project->documents()->create(['type' => config('workflow.intake_key'), 'name' => 'Pasted notes', 'content' => '']);

    $this->post(route('projects.documents.recording.retry', ['project' => $this->project, 'document' => $document]))
        ->assertNotFound();
});
