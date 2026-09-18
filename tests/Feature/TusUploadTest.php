<?php

use App\Jobs\TranscribeRecording;
use App\Models\AiTemplate;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TusUpload;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Uploads one chunk end to end (create -> single PATCH covering the whole body) and returns the
 * created upload's Location URL, mirroring what tus-js-client does for a small blob that fits
 * in one PATCH — every chunk here is small enough that's the normal case.
 */
function uploadTusChunk(Project $project, string $content): string
{
    $create = test()->post(route('projects.browser-recordings.tus.create', $project), [], [
        'Upload-Length' => (string) strlen($content),
        'Tus-Resumable' => '1.0.0',
        'Upload-Metadata' => 'filename '.base64_encode('chunk.webm').',filetype '.base64_encode('audio/webm'),
    ]);
    $create->assertCreated();
    $create->assertHeader('Tus-Resumable', '1.0.0');
    $location = $create->headers->get('Location');

    $patch = test()->call('PATCH', $location, [], [], [], [
        'HTTP_Upload-Offset' => '0',
        'HTTP_Tus-Resumable' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
    ], $content);
    $patch->assertNoContent();
    $patch->assertHeader('Upload-Offset', (string) strlen($content));

    return $location;
}

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
        'name' => 'Tus Upload Project',
        'client_id' => $this->client->id,
    ]);

    $this->actingAs($this->user)->withSession(['active_org_id' => $this->org->id]);
});

it('creates a partial upload and reports its offset via HEAD', function () {
    $response = $this->post(route('projects.browser-recordings.tus.create', $this->project), [], [
        'Upload-Length' => '11',
        'Tus-Resumable' => '1.0.0',
    ]);

    $response->assertCreated();
    $response->assertHeader('Tus-Resumable', '1.0.0');
    $location = $response->headers->get('Location');
    expect($location)->not->toBeNull();

    expect(TusUpload::count())->toBe(1);
    $upload = TusUpload::firstOrFail();
    expect($upload->project_id)->toBe($this->project->id);
    expect($upload->kind)->toBe('partial');
    expect($upload->upload_length)->toBe(11);
    expect($upload->upload_offset)->toBe(0);

    $head = $this->call('HEAD', $location, [], [], [], ['HTTP_Tus-Resumable' => '1.0.0']);
    $head->assertOk();
    $head->assertHeader('Upload-Offset', '0');
    $head->assertHeader('Upload-Length', '11');
});

it('appends a PATCH body and marks the upload complete once the offset reaches the length', function () {
    $location = uploadTusChunk($this->project, 'hello world');

    $upload = TusUpload::firstOrFail();
    expect($upload->upload_offset)->toBe(11);
    expect($upload->isComplete())->toBeTrue();
    expect(Storage::disk('local')->get($upload->storage_path))->toBe('hello world');

    // A PATCH with an offset that doesn't match what the server already has must be rejected
    // (409) rather than silently double-appending — this is what lets tus-js-client's own
    // retry logic safely HEAD-and-resume after a dropped connection.
    $stale = $this->call('PATCH', $location, [], [], [], [
        'HTTP_Upload-Offset' => '0',
        'HTTP_Tus-Resumable' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
    ], 'more');
    $stale->assertStatus(409);
});

it('concatenates completed partials into one document via the shared recording intake', function () {
    Queue::fake();

    $template = AiTemplate::create([
        'name' => 'Transcript to Meeting Notes',
        'type' => 'workflow',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
        'single_output' => true,
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $template->id]);

    // A real WAV header + data (same construction as fakeWavFile() in tests/Pest.php, split
    // into two chunks like a real recording's intervals) — plain text content here would get
    // correctly rejected as non-audio by the same real mimetype detection production traffic
    // goes through, same as it should be.
    $dataSize = 100;
    $header = 'RIFF'.pack('V', 36 + $dataSize).'WAVE'.'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
        .pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8).'data'.pack('V', $dataSize);
    $data = str_repeat("\x00", $dataSize);

    $first = uploadTusChunk($this->project, $header.substr($data, 0, 40));
    $second = uploadTusChunk($this->project, substr($data, 40));

    $response = $this->post(route('projects.browser-recordings.tus.create', $this->project), [], [
        'Upload-Concat' => "final;{$first} {$second}",
        'Tus-Resumable' => '1.0.0',
    ]);

    $response->assertCreated();
    $response->assertHeader('Tus-Resumable', '1.0.0');

    $intake = Document::where('metadata->recording_source', 'browser_capture')->firstOrFail();
    expect($intake->getFirstMedia('recording'))->not->toBeNull();
    $meetingNotes = $this->project->documents()->where('parent_id', $intake->id)->firstOrFail();

    expect($response->json('recording.id'))->toBe($intake->id);
    expect($response->json('recording.notes_id'))->toBe($meetingNotes->id);

    // Both partials and their scratch files are consumed by a successful finalize, not left
    // behind for the cleanup command to find later.
    expect(TusUpload::count())->toBe(0);

    Queue::assertPushed(TranscribeRecording::class, fn ($job) => $job->document->is($intake));
});

it('rejects a final concatenation referencing an incomplete chunk', function () {
    $create = $this->post(route('projects.browser-recordings.tus.create', $this->project), [], [
        'Upload-Length' => '11',
        'Tus-Resumable' => '1.0.0',
    ]);
    $incompleteUrl = $create->headers->get('Location');

    $response = $this->post(route('projects.browser-recordings.tus.create', $this->project), [], [
        'Upload-Concat' => "final;{$incompleteUrl}",
        'Tus-Resumable' => '1.0.0',
    ]);

    $response->assertStatus(400);
    expect(Document::count())->toBe(0);
});

it('rejects tus requests for a project outside the user\'s organization', function () {
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

    $this->post(route('projects.browser-recordings.tus.create', $otherProject), [], [
        'Upload-Length' => '11',
        'Tus-Resumable' => '1.0.0',
    ])->assertNotFound();
});
