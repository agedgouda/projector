<?php

use App\Jobs\TranscribeRecording;
use App\Models\AiTemplate;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\RecordingIntakeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

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

    $this->project = Project::create([
        'name' => 'Browser Capture Project',
        'client_id' => $this->client->id,
    ]);
});

it('uploads a browser-captured recording and tags it with the browser_capture source', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->postJson(route('projects.browser-recordings.store', $this->project), [
            'audio' => fakeWavFile(),
            'name' => 'Client Sync',
        ]);

    $response->assertCreated();
    expect($response->json('recording.status'))->toBe('processing');
    expect($response->json('recording.name'))->toBe('Client Sync');

    $document = Document::findOrFail($response->json('recording.id'));
    expect($document->type)->toBe(config('workflow.intake_key'));
    expect($document->metadata['recording_source'])->toBe('browser_capture');
    expect($document->getFirstMedia('recording'))->not->toBeNull();

    Queue::assertPushed(TranscribeRecording::class, fn ($job) => $job->document->is($document));
});

it('pre-creates a blank Meeting Notes child and reports it as notes_id, same as every other transcript source', function () {
    Queue::fake();

    $template = AiTemplate::create([
        'name' => 'Transcript to Meeting Notes',
        'type' => 'workflow',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
        'single_output' => true,
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $template->id]);

    $response = $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->postJson(route('projects.browser-recordings.store', $this->project), [
            'audio' => fakeWavFile(),
            'name' => 'Client Sync',
        ]);

    $response->assertCreated();
    $intake = Document::findOrFail($response->json('recording.id'));
    $meetingNotes = $this->project->documents()->where('parent_id', $intake->id)->firstOrFail();

    expect($meetingNotes->type)->toBe(config('workflow.action_items_key'))
        ->and($meetingNotes->name)->toBe('Client Sync')
        ->and($meetingNotes->content)->toBe('');

    expect($response->json('recording.notes_id'))->toBe($meetingNotes->id);
});

it('rejects a browser recording upload for a project outside the user\'s organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $otherProject = Project::create([
        'name' => 'Other Project',
        'client_id' => $otherClient->id,
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->postJson(route('projects.browser-recordings.store', $otherProject), ['audio' => fakeWavFile()])
        ->assertNotFound();
});

it('accepts video/webm, because that is what libmagic sniffs a real audio-only WebM/Opus recording as', function () {
    // Reproduced against a real Chrome-recorded getDisplayMedia(audio-only) blob: the browser
    // reports it as audio/webm, but PHP's mimetypes rule validates the libmagic-detected type,
    // not the browser's Content-Type — and libmagic identifies audio-only WebM as video/webm
    // since the container doesn't signal "audio-only" at the level it inspects. Every upload
    // path here only ever sends what MediaRecorder produced from an audio-only stream, so
    // accepting video/webm never actually admits real video.
    expect(explode(',', RecordingIntakeService::RECORDING_MIMES))->toContain('video/webm');
});

it('returns a clean validation error and leaves no orphaned document when the file exceeds the media library size cap', function () {
    Queue::fake();
    config(['media-library.max_file_size' => 100]); // fakeWavFile() is larger than this

    $before = Document::count();

    $response = $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->postJson(route('projects.browser-recordings.store', $this->project), [
            'audio' => fakeWavFile(),
        ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('audio');
    expect($response->json('errors.audio.0'))
        ->toContain('too large')
        ->not->toContain('/tmp') // Spatie's own message leaks the server temp path — ours must not
        ->not->toContain('php');

    // The transaction must have rolled back the placeholder document(s) along with the
    // failed media attachment — no permanently-empty, unrecoverable document left behind.
    expect(Document::count())->toBe($before);
    Queue::assertNotPushed(TranscribeRecording::class);
});

it('rejects a non-audio file uploaded from the browser capture panel', function () {
    $file = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->postJson(route('projects.browser-recordings.store', $this->project), ['audio' => $file])
        ->assertUnprocessable();
});

it('reports processing status while transcription is still running', function () {
    $document = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording — pending',
        'content' => '',
        'processed_at' => now(),
        'metadata' => ['recording_source' => 'browser_capture', 'audio_status' => 'pending'],
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson(route('projects.browser-recordings.status', [$this->project, $document]))
        ->assertOk()
        ->assertJsonPath('recording.status', 'processing');
});

it('reports processed status once transcription has landed', function () {
    $document = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording — done',
        'content' => 'The transcript text.',
        'processed_at' => now(),
        'metadata' => ['recording_source' => 'browser_capture', 'audio_status' => 'pending'],
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson(route('projects.browser-recordings.status', [$this->project, $document]))
        ->assertOk()
        ->assertJsonPath('recording.status', 'processed');
});

it('404s a status check when the document does not belong to the given project', function () {
    $otherProject = Project::create([
        'name' => 'Other Project In Same Org',
        'client_id' => $this->client->id,
    ]);
    $document = $otherProject->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Recording',
        'content' => '',
        // Prevents DocumentObserver's real (unmocked) ProcessDocumentAI auto-dispatch.
        'processed_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson(route('projects.browser-recordings.status', [$this->project, $document]))
        ->assertNotFound();
});
