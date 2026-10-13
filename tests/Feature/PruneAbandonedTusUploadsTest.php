<?php

use App\Jobs\AssembleBrowserRecording;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TusUpload;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * One chunk row and its file, last touched at $lastActivity.
 *
 * @param  array<string, mixed>  $attributes
 */
function createTusUpload(\Carbon\Carbon $lastActivity, string $kind = 'partial', array $attributes = [], ?Project $project = null, ?User $user = null): TusUpload
{
    if ($project === null) {
        $org = Organization::create(['name' => 'Acme Inc']);
        $client = Client::create([
            'organization_id' => $org->id,
            'company_name' => 'Client Co',
            'contact_name' => 'Jane Doe',
            'contact_phone' => '555-1234',
        ]);
        $project = Project::create(['name' => 'Test Project', 'client_id' => $client->id]);
    }

    $path = 'tus-uploads/'.\Illuminate\Support\Str::uuid().'.partial';
    Storage::disk('local')->put($path, 'some bytes');

    $upload = TusUpload::create(array_merge([
        'project_id' => $project->id,
        'creator_id' => ($user ?? User::factory()->create())->id,
        'kind' => $kind,
        'upload_length' => 10,
        'upload_offset' => 0,
        'storage_path' => $path,
    ], $attributes));
    $upload->timestamps = false;
    $upload->created_at = $lastActivity;
    $upload->updated_at = $lastActivity;
    $upload->save();

    return $upload;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createCompleteChunk(\Carbon\Carbon $lastActivity, int $index, Project $project, User $user, array $attributes = []): TusUpload
{
    return createTusUpload($lastActivity, 'partial', array_merge([
        'upload_offset' => 10,
        'completed_at' => $lastActivity,
        'metadata' => ['filename' => "chunk-{$index}.webm", 'recorded_at' => '2026-10-13T15:00:00.000Z'],
    ], $attributes), $project, $user);
}

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
});

it('saves a recording whose tab closed before Stop as a recovered recording', function () {
    $first = createTusUpload(now()->subHours(3));
    $project = $first->project;
    $user = $first->creator;
    $first->delete();

    $chunks = collect(range(0, 2))->map(fn (int $index) => createCompleteChunk(now()->subHours(3), $index, $project, $user));

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    $recovered = Document::where('metadata->recording_source', 'browser_capture')->sole();
    expect($recovered->name)->toStartWith('Recovered recording — ');
    expect($recovered->project_id)->toBe($project->id);

    foreach ($chunks as $chunk) {
        expect($chunk->fresh()->document_id)->toBe($recovered->id);
        expect(Storage::disk('local')->exists($chunk->storage_path))->toBeTrue();
    }
    Queue::assertPushed(AssembleBrowserRecording::class, fn ($job) => $job->document->is($recovered));
});

it('saves two separate abandoned recordings as two documents', function () {
    $seed = createTusUpload(now()->subHours(3));
    $project = $seed->project;
    $user = $seed->creator;
    $seed->delete();

    createCompleteChunk(now()->subHours(3), 0, $project, $user);
    createCompleteChunk(now()->subHours(3), 0, $project, $user, [
        'metadata' => ['filename' => 'chunk-0.webm', 'recorded_at' => '2026-10-13T18:00:00.000Z'],
    ]);

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    expect(Document::where('metadata->recording_source', 'browser_capture')->count())->toBe(2);
});

it('leaves a recording that is still running alone', function () {
    $seed = createTusUpload(now()->subHours(3));
    $project = $seed->project;
    $user = $seed->creator;
    $seed->delete();

    createCompleteChunk(now()->subHours(3), 0, $project, $user);
    createCompleteChunk(now()->subMinutes(5), 1, $project, $user);

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    expect(Document::count())->toBe(0);
    expect(TusUpload::whereNull('document_id')->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('recovers a recently stopped-sending recording straight away with --now', function () {
    $seed = createTusUpload(now()->subHours(3));
    $project = $seed->project;
    $user = $seed->creator;
    $seed->delete();

    createCompleteChunk(now()->subMinutes(5), 0, $project, $user);

    $this->artisan('app:prune-abandoned-tus-uploads', ['--now' => true])->assertSuccessful();

    expect(Document::where('metadata->recording_source', 'browser_capture')->count())->toBe(1);
    Queue::assertPushed(AssembleBrowserRecording::class);
});

it('retries a saved recording whose audio never got attached', function () {
    $seed = createTusUpload(now()->subHours(3));
    $document = $seed->project->documents()->create(['type' => 'transcript', 'name' => 'Recording', 'content' => '']);
    $seed->update(['document_id' => $document->id, 'claimed_at' => now()->subHours(2), 'completed_at' => now()]);

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    Queue::assertPushed(AssembleBrowserRecording::class, fn ($job) => $job->document->is($document));
    expect(Storage::disk('local')->exists($seed->storage_path))->toBeTrue();
});

it('doesn\'t retry a recording that was just stopped', function () {
    $seed = createTusUpload(now()->subMinutes(5));
    $document = $seed->project->documents()->create(['type' => 'transcript', 'name' => 'Recording', 'content' => '']);
    $seed->update(['document_id' => $document->id, 'claimed_at' => now(), 'completed_at' => now()]);

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('keeps a too-large recording\'s chunks without retrying it', function () {
    $seed = createTusUpload(now()->subHours(3));
    $document = $seed->project->documents()->create([
        'type' => 'transcript',
        'name' => 'Recording',
        'content' => '',
        'metadata' => ['audio_too_large' => true],
    ]);
    $seed->update(['document_id' => $document->id, 'claimed_at' => now()->subHours(2), 'completed_at' => now()]);

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(TusUpload::find($seed->id))->not->toBeNull();
    expect(Storage::disk('local')->exists($seed->storage_path))->toBeTrue();
});

it('deletes the chunks of a recording someone deleted', function () {
    $seed = createTusUpload(now()->subHours(3));
    $document = $seed->project->documents()->create(['type' => 'transcript', 'name' => 'Recording', 'content' => '']);
    $seed->update(['document_id' => $document->id, 'claimed_at' => now(), 'completed_at' => now()]);

    $document->forceDelete();

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    expect(TusUpload::find($seed->id))->toBeNull();
    expect(Storage::disk('local')->exists($seed->storage_path))->toBeFalse();
});

it('deletes a chunk whose upload never finished, once it can no longer resume', function () {
    $old = createTusUpload(now()->subHours(48));

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    expect(TusUpload::find($old->id))->toBeNull();
    expect(Storage::disk('local')->exists($old->storage_path))->toBeFalse();
});

it('keeps recent, still in-progress uploads', function () {
    $recent = createTusUpload(now()->subHours(2));

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    expect(TusUpload::find($recent->id))->not->toBeNull();
    expect(Storage::disk('local')->exists($recent->storage_path))->toBeTrue();
});

it('also deletes a stray old final upload', function () {
    $old = createTusUpload(now()->subHours(48), kind: 'final');

    $this->artisan('app:prune-abandoned-tus-uploads')->assertSuccessful();

    expect(TusUpload::find($old->id))->toBeNull();
});
