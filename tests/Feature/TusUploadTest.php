<?php

use App\Jobs\AssembleBrowserRecording;
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
use Illuminate\Support\Str;
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

/**
 * A real WAV header + data, split across two chunks like a real recording's intervals — plain
 * text would be correctly rejected as non-audio by the same mimetype detection production
 * traffic goes through.
 *
 * @return array{0: string, 1: string}
 */
function wavChunks(): array
{
    $dataSize = 100;
    $header = 'RIFF'.pack('V', 36 + $dataSize).'WAVE'.'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
        .pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8).'data'.pack('V', $dataSize);
    $data = str_repeat("\x00", $dataSize);

    return [$header.substr($data, 0, 40), substr($data, 40)];
}

function meetingNotesTemplate(): void
{
    $template = AiTemplate::create([
        'name' => 'Transcript to Meeting Notes',
        'type' => 'workflow',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
        'single_output' => true,
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $template->id]);
}

/**
 * A completed chunk row and its file, made directly — for lists far longer than is practical
 * to push through the real upload endpoints.
 */
function storedChunk(Project $project, User $user, int $index, string $content = 'x', array $attributes = []): TusUpload
{
    $path = 'tus-uploads/'.Str::orderedUuid().'.partial';
    Storage::disk('local')->put($path, $content);

    return TusUpload::create(array_merge([
        'project_id' => $project->id,
        'creator_id' => $user->id,
        'kind' => 'partial',
        'upload_length' => strlen($content),
        'upload_offset' => strlen($content),
        'storage_path' => $path,
        'metadata' => ['filename' => "chunk-{$index}.webm", 'recorded_at' => '2026-10-13T15:00:00.000Z'],
        'completed_at' => now(),
    ], $attributes));
}

it('saves the recording at Stop and assembles its audio in the background', function () {
    Queue::fake();
    meetingNotesTemplate();

    [$firstAudio, $secondAudio] = wavChunks();
    $first = uploadTusChunk($this->project, $firstAudio);
    $second = uploadTusChunk($this->project, $secondAudio);

    $response = $this->postJson(route('projects.browser-recordings.tus.finish', $this->project), [
        'upload_ids' => [basename($first), basename($second)],
    ]);

    $response->assertCreated();

    $intake = Document::where('metadata->recording_source', 'browser_capture')->firstOrFail();
    $meetingNotes = $this->project->documents()->where('parent_id', $intake->id)->firstOrFail();
    expect($response->json('recording.id'))->toBe($intake->id);
    expect($response->json('recording.notes_id'))->toBe($meetingNotes->id);

    // Nothing is assembled or deleted yet — the chunks are claimed for the saved document.
    expect($intake->getFirstMedia('recording'))->toBeNull();
    expect(TusUpload::where('document_id', $intake->id)->count())->toBe(2);
    Queue::assertPushed(AssembleBrowserRecording::class, fn ($job) => $job->document->is($intake));
    Queue::assertNotPushed(TranscribeRecording::class);

    app()->call([new AssembleBrowserRecording($intake), 'handle']);

    expect($intake->fresh()->getFirstMedia('recording'))->not->toBeNull();
    expect(TusUpload::count())->toBe(0);
    Queue::assertPushed(TranscribeRecording::class, fn ($job) => $job->document->is($intake));
});

it('accepts a long recording\'s chunk list, which used to be too big for a request header', function () {
    Queue::fake();

    $ids = collect(range(0, 299))
        ->map(fn (int $index) => storedChunk($this->project, $this->user, $index)->id)
        ->all();

    $this->postJson(route('projects.browser-recordings.tus.finish', $this->project), ['upload_ids' => $ids])
        ->assertCreated();

    expect(TusUpload::whereNotNull('document_id')->count())->toBe(300);
});

it('joins the chunks in recording order whatever order they arrive in', function () {
    Queue::fake();

    $third = storedChunk($this->project, $this->user, 2, 'C');
    $first = storedChunk($this->project, $this->user, 0, 'A');
    $second = storedChunk($this->project, $this->user, 1, 'B');

    $this->postJson(route('projects.browser-recordings.tus.finish', $this->project), [
        'upload_ids' => [$third->id, $first->id, $second->id],
    ])->assertCreated();

    $ordered = TusUpload::inRecordingOrder(TusUpload::all());
    expect($ordered->map(fn (TusUpload $chunk) => Storage::disk('local')->get($chunk->storage_path))->implode(''))->toBe('ABC');
});

it('returns the same recording when Stop is retried after it already went through', function () {
    Queue::fake();

    $ids = [storedChunk($this->project, $this->user, 0)->id, storedChunk($this->project, $this->user, 1)->id];
    $route = route('projects.browser-recordings.tus.finish', $this->project);

    $firstId = $this->postJson($route, ['upload_ids' => $ids])->assertCreated()->json('recording.id');
    $retryId = $this->postJson($route, ['upload_ids' => $ids])->assertCreated()->json('recording.id');

    expect($retryId)->toBe($firstId);
    expect(Document::where('metadata->recording_source', 'browser_capture')->count())->toBe(1);
    Queue::assertPushed(AssembleBrowserRecording::class, 1);
});

it('still accepts Stop from a tab running the previous version of the page', function () {
    Queue::fake();

    [$firstAudio, $secondAudio] = wavChunks();
    $first = uploadTusChunk($this->project, $firstAudio);
    $second = uploadTusChunk($this->project, $secondAudio);

    $this->post(route('projects.browser-recordings.tus.create', $this->project), [], [
        'Upload-Concat' => "final;{$first} {$second}",
        'Tus-Resumable' => '1.0.0',
    ])->assertCreated();

    Queue::assertPushed(AssembleBrowserRecording::class);
});

it('rejects Stop without valid chunk ids', function (array $payload, string $errorKey) {
    $this->postJson(route('projects.browser-recordings.tus.finish', $this->project), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);
})->with([
    'missing' => [[], 'upload_ids'],
    'empty' => [['upload_ids' => []], 'upload_ids'],
    'not ids' => [['upload_ids' => ['not-a-uuid']], 'upload_ids.0'],
]);

it('rejects a final concatenation referencing an incomplete chunk', function () {
    $create = $this->post(route('projects.browser-recordings.tus.create', $this->project), [], [
        'Upload-Length' => '11',
        'Tus-Resumable' => '1.0.0',
    ]);
    $incompleteUrl = $create->headers->get('Location');

    $this->postJson(route('projects.browser-recordings.tus.finish', $this->project), [
        'upload_ids' => [basename($incompleteUrl)],
    ])->assertStatus(400);

    expect(Document::count())->toBe(0);
});

it('ignores chunk ids from another project', function () {
    $otherProject = Project::create(['name' => 'Other', 'client_id' => $this->client->id]);
    $foreign = storedChunk($otherProject, $this->user, 0);

    $this->postJson(route('projects.browser-recordings.tus.finish', $this->project), [
        'upload_ids' => [$foreign->id],
    ])->assertStatus(400);

    expect($foreign->fresh()->document_id)->toBeNull();
});

it('keeps the chunks when the audio can\'t be attached, so a retry can still save it', function () {
    Queue::fake();

    $chunk = storedChunk($this->project, $this->user, 0, 'not audio at all');
    $this->postJson(route('projects.browser-recordings.tus.finish', $this->project), ['upload_ids' => [$chunk->id]])
        ->assertCreated();
    $intake = Document::where('metadata->recording_source', 'browser_capture')->firstOrFail();

    $job = new AssembleBrowserRecording($intake);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(Exception::class);
    $job->failed(new RuntimeException('boom'));

    expect(Storage::disk('local')->exists($chunk->storage_path))->toBeTrue();
    expect($chunk->fresh()->document_id)->toBe($intake->id);
    expect($intake->fresh()->metadata['transcription_error'])->toContain('The audio is saved');
});

it('keeps a too-large recording\'s chunks and stops retrying it', function () {
    Queue::fake();
    config(['media-library.max_file_size' => 10]);

    [$firstAudio, $secondAudio] = wavChunks();
    $first = storedChunk($this->project, $this->user, 0, $firstAudio);
    $second = storedChunk($this->project, $this->user, 1, $secondAudio);
    $this->postJson(route('projects.browser-recordings.tus.finish', $this->project), ['upload_ids' => [$first->id, $second->id]])
        ->assertCreated();
    $intake = Document::where('metadata->recording_source', 'browser_capture')->firstOrFail();

    app()->call([new AssembleBrowserRecording($intake), 'handle']);

    $metadata = $intake->fresh()->metadata;
    expect($metadata['audio_too_large'])->toBeTrue();
    expect($metadata['transcription_error'])->toContain('The audio is saved');
    expect(TusUpload::where('document_id', $intake->id)->count())->toBe(2);
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
