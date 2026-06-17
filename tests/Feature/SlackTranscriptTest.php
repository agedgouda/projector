<?php

use App\Jobs\ImportMeetingTranscript;
use App\Jobs\ProcessDocumentAI;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Fake the Slack Web API with a happy-path response.
 *
 * @param  array  $files  Files to return from files.list
 * @param  array  $fileInfo  File payload to return from files.info
 */
function fakeSlackApi(array $files = [], array $fileInfo = []): void
{
    Http::fake([
        'slack.com/api/files.list*' => Http::response([
            'ok' => true,
            'files' => $files,
        ], 200),

        'slack.com/api/files.info*' => Http::response([
            'ok' => true,
            'file' => $fileInfo,
        ], 200),
    ]);
}

// ── Setup ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->org->meeting_provider = 'slack';
    $this->org->meeting_config = [
        'bot_token' => 'xoxb-fake-token',
    ];
    $this->org->save();

    $projectType = ProjectType::create(['name' => 'General', 'document_schema' => []]);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
        'project_type_id' => $projectType->id,
    ]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

// ── Index: listing recordings ─────────────────────────────────────────────────

it('returns available recordings from slack', function () {
    fakeSlackApi(files: [
        [
            'id' => 'F123',
            'title' => 'Weekly Sync Clip',
            'timestamp' => 1740825600,
            'duration_ms' => 90000,
            'transcription' => ['status' => 'complete'],
        ],
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Transcripts')
            ->where('provider', 'slack')
            ->where('providerError', null)
            ->has('recordings', 1)
            ->where('recordings.0.id', 'F123')
            ->where('recordings.0.title', 'Weekly Sync Clip')
            ->where('recordings.0.duration_minutes', 2)
        );
});

it('excludes slack files with no completed transcription', function () {
    fakeSlackApi(files: [
        [
            'id' => 'F456',
            'title' => 'Untranscribed Clip',
            'timestamp' => 1740825600,
            'transcription' => ['status' => 'processing'],
        ],
        [
            'id' => 'F789',
            'title' => 'Plain File',
            'timestamp' => 1740825600,
        ],
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('recordings', []));
});

it('passes provider error when slack api fails', function () {
    Http::fake([
        'slack.com/api/files.list*' => Http::response(['ok' => false, 'error' => 'invalid_auth'], 200),
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Transcripts')
            ->where('provider', 'slack')
            ->whereNot('providerError', null)
            ->where('recordings', [])
        );
});

// ── Store: dispatching import job ─────────────────────────────────────────────

it('dispatches import job for a slack recording', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.store', $this->project), [
            'recording_id' => 'F123',
            'title' => 'Weekly Sync Clip',
            'started_at' => '2026-03-01T10:00:00Z',
        ])
        ->assertRedirect();

    $document = $this->project->documents()->where('type', 'intake')->first();
    expect($document)->not->toBeNull()
        ->and($document->metadata['recording_id'])->toBe('F123')
        ->and($document->metadata['provider'])->toBe('slack');

    Queue::assertPushed(ImportMeetingTranscript::class, function ($job) use ($document) {
        return $job->recordingId === 'F123'
            && $job->document->is($document);
    });
});

// ── Job: ImportMeetingTranscript ──────────────────────────────────────────────

it('job fetches slack transcript and updates the placeholder document', function () {
    Queue::fake([ProcessDocumentAI::class]);

    fakeSlackApi(fileInfo: [
        'id' => 'F123',
        'transcription' => [
            'status' => 'complete',
            'preview' => ['content' => 'Hello everyone. Thanks for joining.'],
        ],
    ]);

    $document = $this->project->documents()->create([
        'type' => 'intake',
        'name' => 'Weekly Sync Clip',
        'content' => '',
        'processed_at' => now(),
        'metadata' => [
            'recording_id' => 'F123',
            'provider' => 'slack',
            'meeting_date' => '2026-03-01T10:00:00Z',
        ],
    ]);

    $job = new ImportMeetingTranscript($document, 'F123');
    $job->handle(app(\App\Services\MeetingTranscriptService::class));

    $document->refresh();

    expect($document->content)->toContain('Hello everyone. Thanks for joining.')
        ->and($document->processed_at)->toBeNull()
        ->and($document->metadata['provider'])->toBe('slack');

    Queue::assertPushed(ProcessDocumentAI::class);
});

it('job marks placeholder as processed when slack transcript is empty', function () {
    Queue::fake();

    fakeSlackApi(fileInfo: [
        'id' => 'F999',
        'transcription' => ['status' => 'failed'],
    ]);

    $document = $this->project->documents()->create([
        'type' => 'transcript',
        'name' => 'Empty Clip',
        'content' => '',
        'metadata' => ['recording_id' => 'F999', 'provider' => 'slack'],
    ]);

    $job = new ImportMeetingTranscript($document, 'F999');
    $job->handle(app(\App\Services\MeetingTranscriptService::class));

    $document->refresh();
    expect($document->processed_at)->not->toBeNull();
    Queue::assertNotPushed(ProcessDocumentAI::class);
});
