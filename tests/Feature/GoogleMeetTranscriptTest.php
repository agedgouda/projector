<?php

use App\Jobs\GenerateDocumentEmbedding;
use App\Jobs\ImportMeetingTranscript;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// Pre-generated RSA private key for tests (not used for real security).
const TEST_RSA_PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCzK2GerCn5+mtF
XCGFoS0GFIVDWPx4sc6sjOIWZylJkUypZa6JO1h+LMBGjTsnoNJ0Uz+FfNeBJnpV
JbBiz/WF+TGmILPJgVBQbh9lJdT8IWwQG9vu4il8zzLhsPotfRJuhc+34iVNXWeN
L+7irBJ3TWUwdqeZKTTYa8ELt5zpl//sQPpTmLgfqc1SresVLxKMoVKm+Tb29v4o
8IZ2af8PHy6rXa/KzUF0IIO18PYsamZBuC/+1JY4uN9E8XLrfpW/A/JSLONcsLUp
+AKlz3xJdH+yx0x0kGmALRAKXNaP9jXHMy00KMwruUkfzek4cqr8Gr9e4UDvPNU1
H/Zjcn43AgMBAAECggEABXTY5KGkBRbmEOPjZTHC/WSL7RYSroz2XWh/oijlDjI8
nByaoqybEoQZC8AzNA7zxH3PvY61wOfyrlVsb755C5SosLA73siaDwXNqm5AYy/6
cy9oJc2bCVAFjIk4cPyahNR9E+PgJ77gEaYkuLEpYGfyVAccPwMNDEilZ72L54go
VJ3jsgMEV+LQNHy9mz858z046gNu1eni8JKjGrpyCo4RcgYHaHWcmzcOd5jlPwr0
C/ZFneaHu6GTchYtLJ7UZc8FsFKooYg09wBRN5A55y7PzJsE1PILiXOqraM6T0pW
xK2tPLdrP0jo81r9dS4HZs1AphSl7HtX2s3pn7N/oQKBgQDa3Jx5uGoiiTYdflXy
QunEM0F4xNHzZPePbYy/kTiHQ3Ev8OSY+XXy/DVFIiKaL1GnN4nMChZPtOtE0I25
m6G6L3dmWPmdNcG9N7Am/LMbYzXKvZ8aHDXCNHgfY6eYf/riUuSDUi8JF+lkqEgE
Z7cYVYPSK6ibqMoc2K0B3/5eKQKBgQDRkoe/gMDl1ZBa8HUv8VdDdr36X2XvwGwx
yjhctGy69rYeUjfZdiRcGGVpIPq7xJDm7Ilo+p/l4ojIlnz1aKj+VS+Th/C+nHzi
vSa810oO2fFDQLG8Gz0f9rE2VBmntBhObsKtaBdI5VfFnXIfSE6x/qqhUYfcjtC0
L7kR29jFXwKBgQCdVMoHllJ10T1dplwSc5eou0/tiU+EMKjmKlJrw/FnC0xqsjki
3vGzYyrIL+m//RQtqPcd/oJbeitGpMW9D+wK8+RZZszJ/sLvSwEOhcobfg5FWFrv
YigjG6Teq7znG9k2qXAsFwBJAS9+dJqQ0B4HGDJS+5+Rhp0Eb56vIl6xQQKBgB57
/w0HMIf+GcJwqcswgu4ITrglulE2n0Za0PoDMLG9g15DvWX/fmh7D/1L080OWbmN
fKttkWbCHf13jnOwJqzgPUxrgMRpRO1CcGkVs+sXHrYWqgPfvnNw0fRCJX00gDig
eeb5djGfUrYnIduVDAVuMunGT0nw6EDdlEEZxp1nAoGASeF9R/ypPmnFn7KyJSSt
qXcFGr8Nw7+gTEpqGWVLOAd0y0ZFcb0Lo4+5ImNqGfehciJFPI5ybqpsGAhU8YTo
Fb/eFPwc/eBvvLFOcZvudENyb3Gnqz66wKbOj/L1eG/nKLZT1iofEeZyb+dtsCMd
ooBSKE/fOjRETEcGUfr+d5c=
-----END PRIVATE KEY-----
PEM;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Fake all Google Meet API calls with a happy-path response.
 *
 * @param  array  $conferences  Conference records to return
 * @param  array  $entries  Transcript entries to return
 */
function fakeGoogleMeetApi(array $conferences = [], array $entries = []): void
{
    $transcripts = ! empty($conferences)
        ? [['name' => "{$conferences[0]['name']}/transcripts/t1"]]
        : [];

    Http::fake([
        // OAuth token exchange
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fake-google-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),

        // Transcript entries (more specific — must come before the transcripts wildcard)
        'meet.googleapis.com/v2/*/transcripts/*/entries*' => Http::response([
            'transcriptEntries' => $entries,
        ], 200),

        // Transcripts list for each conference
        'meet.googleapis.com/v2/*/transcripts*' => Http::response([
            'transcripts' => $transcripts,
        ], 200),

        // Conference records list
        'meet.googleapis.com/v2/conferenceRecords*' => Http::response([
            'conferenceRecords' => $conferences,
        ], 200),
    ]);
}

// ── Setup ─────────────────────────────────────────────────────────────────────

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->org->meeting_provider = 'google_meet';
    $this->org->meeting_config = [
        'service_account_email' => 'bot@project.iam.gserviceaccount.com',
        'private_key' => TEST_RSA_PRIVATE_KEY,
        'impersonate_email' => 'user@workspace.com',
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

// ── Index: access control ─────────────────────────────────────────────────────

it('allows org member to view transcripts page', function () {
    fakeGoogleMeetApi();

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Transcripts'));
});

it('denies unauthenticated access to transcripts page', function () {
    $this->get(route('projects.transcripts.index', $this->project))
        ->assertRedirect();
});

// ── Index: provider state ─────────────────────────────────────────────────────

it('passes null provider when none is configured', function () {
    $this->org->meeting_provider = null;
    $this->org->meeting_config = null;
    $this->org->save();

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Transcripts')
            ->where('provider', null)
            ->where('recordings', [])
        );
});

it('passes provider error when google meet api fails', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 401),
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Transcripts')
            ->where('provider', 'google_meet')
            ->whereNot('providerError', null)
            ->where('recordings', [])
        );
});

// ── Index: listing recordings ─────────────────────────────────────────────────

it('returns available recordings from google meet', function () {
    fakeGoogleMeetApi(conferences: [
        [
            'name' => 'conferenceRecords/abc123',
            'startTime' => '2026-03-01T10:00:00Z',
            'endTime' => '2026-03-01T10:30:00Z',
        ],
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Projects/Transcripts')
            ->where('provider', 'google_meet')
            ->where('providerError', null)
            ->has('recordings', 1)
            ->where('recordings.0.id', 'conferenceRecords/abc123')
            ->where('recordings.0.duration_minutes', 30)
        );
});

it('excludes conferences with no transcripts available', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-token'], 200),
        'meet.googleapis.com/v2/conferenceRecords*' => Http::response([
            'conferenceRecords' => [
                ['name' => 'conferenceRecords/no-transcript', 'startTime' => '2026-03-01T10:00:00Z'],
            ],
        ], 200),
        'meet.googleapis.com/v2/*/transcripts*' => Http::response(['transcripts' => []], 200),
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('recordings', []));
});

it('marks already-imported recordings in importedIds', function () {
    fakeGoogleMeetApi(conferences: [
        ['name' => 'conferenceRecords/already', 'startTime' => '2026-03-01T10:00:00Z', 'endTime' => '2026-03-01T10:15:00Z'],
        ['name' => 'conferenceRecords/new',     'startTime' => '2026-03-02T09:00:00Z', 'endTime' => '2026-03-02T09:45:00Z'],
    ]);

    $this->project->documents()->create([
        'type' => 'transcript',
        'name' => 'Existing Transcript',
        'content' => 'hello world',
        'metadata' => ['recording_id' => 'conferenceRecords/already', 'provider' => 'google_meet'],
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('importedIds', 1)
            ->where('importedIds.0', 'conferenceRecords/already')
        );
});

// ── Store: dispatching import job ─────────────────────────────────────────────

it('dispatches import job for a recording', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.store', $this->project), [
            'recording_id' => 'conferenceRecords/abc123',
            'title' => 'Weekly Sync — 2026-03-01',
            'started_at' => '2026-03-01T10:00:00Z',
        ])
        ->assertRedirect();

    // A placeholder document should be created immediately
    $document = $this->project->documents()->where('type', 'transcript')->first();
    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Weekly Sync — 2026-03-01')
        ->and($document->processed_at)->toBeNull()
        ->and($document->metadata['recording_id'])->toBe('conferenceRecords/abc123');

    Queue::assertPushed(ImportMeetingTranscript::class, function ($job) use ($document) {
        return $job->recordingId === 'conferenceRecords/abc123'
            && $job->document->is($document);
    });
});

it('prevents duplicate imports of the same recording', function () {
    Queue::fake();

    $this->project->documents()->create([
        'type' => 'transcript',
        'name' => 'Existing',
        'content' => 'hello',
        'metadata' => ['recording_id' => 'conferenceRecords/abc123'],
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.store', $this->project), [
            'recording_id' => 'conferenceRecords/abc123',
            'title' => 'Duplicate',
            'started_at' => '2026-03-01T10:00:00Z',
        ])
        ->assertSessionHasErrors('recording_id');

    // No new document should have been created
    expect($this->project->documents()->count())->toBe(1);
    Queue::assertNotPushed(ImportMeetingTranscript::class);
});

it('validates required fields on store', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.store', $this->project), [])
        ->assertSessionHasErrors(['recording_id', 'title', 'started_at']);
});

// ── Job: ImportMeetingTranscript ──────────────────────────────────────────────

it('job fetches transcript and updates the placeholder document', function () {
    Queue::fake([GenerateDocumentEmbedding::class]);

    fakeGoogleMeetApi(
        conferences: [['name' => 'conferenceRecords/abc123', 'startTime' => '2026-03-01T10:00:00Z']],
        entries: [
            ['text' => 'Hello everyone.'],
            ['text' => 'Thanks for joining.'],
        ]
    );

    $document = $this->project->documents()->create([
        'type' => 'transcript',
        'name' => 'Weekly Sync',
        'content' => '',
        'metadata' => [
            'recording_id' => 'conferenceRecords/abc123',
            'provider' => 'google_meet',
            'meeting_date' => '2026-03-01T10:00:00Z',
        ],
    ]);

    $job = new ImportMeetingTranscript($document, 'conferenceRecords/abc123');
    $job->handle(app(\App\Services\MeetingTranscriptService::class));

    $document->refresh();

    expect($document->type)->toBe('transcript')
        ->and($document->content)->toContain('Hello everyone.')
        ->and($document->content)->toContain('Thanks for joining.')
        ->and($document->metadata['recording_id'])->toBe('conferenceRecords/abc123')
        ->and($document->metadata['provider'])->toBe('google_meet')
        ->and($document->metadata['meeting_date'])->toBe('2026-03-01T10:00:00Z');

    Queue::assertPushed(GenerateDocumentEmbedding::class);
});

it('job marks placeholder as processed when transcript is empty', function () {
    Queue::fake();

    fakeGoogleMeetApi(
        conferences: [['name' => 'conferenceRecords/empty', 'startTime' => '2026-03-01T10:00:00Z']],
        entries: []
    );

    $document = $this->project->documents()->create([
        'type' => 'transcript',
        'name' => 'Empty Meeting',
        'content' => '',
        'metadata' => ['recording_id' => 'conferenceRecords/empty', 'provider' => 'google_meet'],
    ]);

    $job = new ImportMeetingTranscript($document, 'conferenceRecords/empty');
    $job->handle(app(\App\Services\MeetingTranscriptService::class));

    $document->refresh();
    expect($document->processed_at)->not->toBeNull();
    Queue::assertNotPushed(GenerateDocumentEmbedding::class);
});

it('job marks placeholder as processed when organization has no meeting provider', function () {
    Queue::fake();

    $this->org->meeting_provider = null;
    $this->org->meeting_config = null;
    $this->org->save();

    $document = $this->project->documents()->create([
        'type' => 'transcript',
        'name' => 'Some Meeting',
        'content' => '',
        'metadata' => ['recording_id' => 'conferenceRecords/abc123'],
    ]);

    $job = new ImportMeetingTranscript($document, 'conferenceRecords/abc123');
    $job->handle(app(\App\Services\MeetingTranscriptService::class));

    $document->refresh();
    expect($document->processed_at)->not->toBeNull();
    Queue::assertNotPushed(GenerateDocumentEmbedding::class);
});

// ── Config: google meet org settings ─────────────────────────────────────────

it('stores google meet service account config', function () {
    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => $this->org->name,
            'meeting_provider' => 'google_meet',
            'meeting_config' => [
                'service_account_email' => 'bot@project.iam.gserviceaccount.com',
                'private_key' => "-----BEGIN PRIVATE KEY-----\nfake\n-----END PRIVATE KEY-----\n",
                'impersonate_email' => 'user@workspace.com',
            ],
        ])
        ->assertRedirect();

    $this->org->refresh();

    expect($this->org->meeting_provider)->toBe('google_meet')
        ->and($this->org->meeting_config['service_account_email'])->toBe('bot@project.iam.gserviceaccount.com')
        ->and($this->org->meeting_config['impersonate_email'])->toBe('user@workspace.com')
        ->and($this->org->meeting_config['private_key'])->toContain('BEGIN PRIVATE KEY');
});

it('preserves existing private key when blank is submitted', function () {
    $this->org->meeting_config = [
        'service_account_email' => 'bot@project.iam.gserviceaccount.com',
        'private_key' => 'existing-key',
        'impersonate_email' => 'user@workspace.com',
    ];
    $this->org->save();

    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => $this->org->name,
            'meeting_provider' => 'google_meet',
            'meeting_config' => [
                'service_account_email' => 'bot@project.iam.gserviceaccount.com',
                'private_key' => '',
                'impersonate_email' => 'user@workspace.com',
            ],
        ])
        ->assertRedirect();

    $this->org->refresh();

    expect($this->org->meeting_config['private_key'])->toBe('existing-key');
});

it('exposes google meet config form fields on edit page', function () {
    $this->org->meeting_provider = 'google_meet';
    $this->org->meeting_config = [
        'service_account_email' => 'bot@project.iam.gserviceaccount.com',
        'private_key' => 'secret-key',
        'impersonate_email' => 'user@workspace.com',
    ];
    $this->org->save();

    $this->actingAs($this->admin)
        ->get(route('organizations.edit', $this->org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Organizations/Edit')
            ->where('organization.meeting_provider', 'google_meet')
            ->where('organization.meeting_config_form.service_account_email', 'bot@project.iam.gserviceaccount.com')
            ->where('organization.meeting_config_form.impersonate_email', 'user@workspace.com')
            ->where('organization.meeting_config_form.has_private_key', true)
        );
});
