<?php

use App\Jobs\ImportMeetingTranscript;
use App\Jobs\ProcessDocumentAI;
use App\Models\AiTemplate;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
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
 * @param  array  $entries  Transcript entries to return for the (single) transcript segment
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

/**
 * Fake a conference whose transcription was stopped and restarted mid-meeting,
 * producing multiple separate transcript segments each with their own entries.
 *
 * @param  array<int, array{name: string, startTime: string, entries: array}>  $segments
 */
function fakeGoogleMeetApiWithSegments(array $conference, array $segments): void
{
    $transcripts = array_map(fn ($s) => ['name' => $s['name'], 'startTime' => $s['startTime']], $segments);

    $entryFakes = [];
    foreach ($segments as $segment) {
        $entryFakes["meet.googleapis.com/v2/{$segment['name']}/entries*"] = Http::response([
            'transcriptEntries' => $segment['entries'],
        ], 200);
    }

    Http::fake($entryFakes + [
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fake-google-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ], 200),

        "meet.googleapis.com/v2/{$conference['name']}/transcripts*" => Http::response([
            'transcripts' => $transcripts,
        ], 200),

        'meet.googleapis.com/v2/conferenceRecords*' => Http::response([
            'conferenceRecords' => [$conference],
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

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
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

it('excludes conferences with neither a transcript nor a recording', function () {
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

    // A placeholder document should be created immediately as type 'intake' with
    // processed_at set to prevent premature ProcessDocumentAI dispatch.
    $document = $this->project->documents()->where('type', 'intake')->first();
    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Weekly Sync — 2026-03-01')
        ->and($document->processed_at)->not->toBeNull()
        ->and($document->metadata['recording_id'])->toBe('conferenceRecords/abc123');

    Queue::assertPushed(ImportMeetingTranscript::class, function ($job) use ($document) {
        return $job->recordingId === 'conferenceRecords/abc123'
            && $job->document->is($document);
    });
});

it('persists an optional custom_prompt on the placeholder document', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.store', $this->project), [
            'recording_id' => 'conferenceRecords/abc123',
            'title' => 'Weekly Sync — 2026-03-01',
            'started_at' => '2026-03-01T10:00:00Z',
            'custom_prompt' => 'Clean this up into full meeting notes, eliminating anything personal.',
        ])
        ->assertRedirect();

    $document = $this->project->documents()->where('type', 'intake')->first();
    expect($document->custom_prompt)->toBe('Clean this up into full meeting notes, eliminating anything personal.');
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

it('imports a recording as an existing non-intake type with no AI step, exactly like a Google Doc/file picked as that type', function () {
    // resolveDocumentType() (via DocumentTypeResolver) validates a picked (non-new,
    // non-intake) type against types actually already in use in this project.
    $this->project->documents()->create([
        'type' => config('workflow.action_items_key'),
        'name' => 'Existing Meeting Notes',
        'content' => 'Pre-existing.',
        'processed_at' => now(),
    ]);

    fakeGoogleMeetApi(
        conferences: [['name' => 'conferenceRecords/abc123', 'startTime' => '2026-03-01T10:00:00Z']],
        entries: [['text' => 'Raw transcript text.']],
    );

    $response = $this->actingAs($this->admin)
        ->post(route('projects.transcripts.store', $this->project), [
            'recording_id' => 'conferenceRecords/abc123',
            'title' => 'Weekly Sync — 2026-03-01',
            'started_at' => '2026-03-01T10:00:00Z',
            'custom_prompt' => 'This should be ignored — no AI step applies.',
            'type' => config('workflow.action_items_key'),
        ]);

    $document = $this->project->documents()->where('name', 'Weekly Sync — 2026-03-01')->firstOrFail();

    expect($document->type)->toBe(config('workflow.action_items_key'))
        ->and($document->content)->toContain('Raw transcript text.')
        ->and($document->processed_at)->not->toBeNull()
        ->and($document->parent_id)->toBeNull();

    // No Meeting Notes child ever got pre-created for it — that pre-create-and-redirect
    // step only exists for the intake/AI-generation branch (see ImportMeetingTranscript::
    // handle()'s early return for a non-intake type).
    expect($this->project->documents()->where('parent_id', $document->id)->exists())->toBeFalse();

    // Sent straight to the document's own page (same as every other import source now),
    // where the fetch finishes rather than staying on the modal/tab it was imported from.
    $response->assertRedirect(route('projects.documents.show', [$this->project, $document]));
});

it('redirects to a pre-created blank Meeting Notes document when the transcript-to-notes template is single-output', function () {
    Queue::fake();

    $template = AiTemplate::create([
        'name' => 'Transcript to Meeting Notes',
        'type' => 'workflow',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
        'single_output' => true,
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $template->id]);

    $response = $this->actingAs($this->admin)
        ->post(route('projects.transcripts.store', $this->project), [
            'recording_id' => 'conferenceRecords/abc123',
            'title' => 'Weekly Sync — 2026-03-01',
            'started_at' => '2026-03-01T10:00:00Z',
        ]);

    $intake = $this->project->documents()->where('type', 'intake')->firstOrFail();
    $meetingNotes = $this->project->documents()->where('parent_id', $intake->id)->firstOrFail();

    expect($meetingNotes->type)->toBe(config('workflow.action_items_key'))
        ->and($meetingNotes->name)->toBe('Weekly Sync — 2026-03-01')
        ->and($meetingNotes->content)->toBe('')
        ->and($meetingNotes->processed_at)->toBeNull();

    $response->assertRedirect(route('projects.documents.show', [$this->project, $meetingNotes]));
});

it('redirects straight to the transcript when the transcript-to-notes template is not single-output', function () {
    Queue::fake();

    $template = AiTemplate::create([
        'name' => 'Transcript to Meeting Notes',
        'type' => 'workflow',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
        'single_output' => false,
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $template->id]);

    $response = $this->actingAs($this->admin)
        ->post(route('projects.transcripts.store', $this->project), [
            'recording_id' => 'conferenceRecords/abc123',
            'title' => 'Weekly Sync — 2026-03-01',
            'started_at' => '2026-03-01T10:00:00Z',
        ]);

    $intake = $this->project->documents()->where('type', 'intake')->firstOrFail();
    expect($this->project->documents()->where('parent_id', $intake->id)->exists())->toBeFalse();

    $response->assertRedirect(route('projects.documents.show', [$this->project, $intake]));
});

it('validates required fields on store', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.store', $this->project), [])
        ->assertSessionHasErrors(['recording_id', 'title', 'started_at']);
});

// ── Job: ImportMeetingTranscript ──────────────────────────────────────────────

it('job fetches transcript and updates the placeholder document', function () {
    Queue::fake([ProcessDocumentAI::class]);

    fakeGoogleMeetApi(
        conferences: [['name' => 'conferenceRecords/abc123', 'startTime' => '2026-03-01T10:00:00Z']],
        entries: [
            ['text' => 'Hello everyone.'],
            ['text' => 'Thanks for joining.'],
        ]
    );

    $document = $this->project->documents()->create([
        'type' => 'intake',
        'name' => 'Weekly Sync',
        'content' => '',
        'processed_at' => now(),
        'metadata' => [
            'recording_id' => 'conferenceRecords/abc123',
            'provider' => 'google_meet',
            'meeting_date' => '2026-03-01T10:00:00Z',
        ],
    ]);

    $job = new ImportMeetingTranscript($document, 'conferenceRecords/abc123');
    $job->handle(app(\App\Services\MeetingTranscriptService::class));

    $document->refresh();

    expect($document->type)->toBe('intake')
        ->and($document->content)->toContain('Hello everyone.')
        ->and($document->content)->toContain('Thanks for joining.')
        ->and($document->processed_at)->toBeNull()
        ->and($document->metadata['recording_id'])->toBe('conferenceRecords/abc123')
        ->and($document->metadata['provider'])->toBe('google_meet')
        ->and($document->metadata['meeting_date'])->toBe('2026-03-01T10:00:00Z');

    Queue::assertPushed(ProcessDocumentAI::class);
});

it('job concatenates all transcript segments when transcription was stopped and restarted', function () {
    Queue::fake([ProcessDocumentAI::class]);

    $conference = ['name' => 'conferenceRecords/split', 'startTime' => '2026-03-01T10:00:00Z'];
    fakeGoogleMeetApiWithSegments($conference, [
        [
            'name' => 'conferenceRecords/split/transcripts/second',
            'startTime' => '2026-03-01T10:15:00Z',
            'entries' => [['text' => 'Second half of the meeting.']],
        ],
        [
            'name' => 'conferenceRecords/split/transcripts/first',
            'startTime' => '2026-03-01T10:00:00Z',
            'entries' => [['text' => 'First half of the meeting.']],
        ],
    ]);

    $document = $this->project->documents()->create([
        'type' => 'intake',
        'name' => 'Restarted Meeting',
        'content' => '',
        'processed_at' => now(),
        'metadata' => ['recording_id' => 'conferenceRecords/split', 'provider' => 'google_meet'],
    ]);

    $job = new ImportMeetingTranscript($document, 'conferenceRecords/split');
    $job->handle(app(\App\Services\MeetingTranscriptService::class));

    $document->refresh();

    // Segments must be concatenated in chronological order (by startTime), not API
    // response order — the fake above returns "second" before "first" on purpose.
    $firstPos = strpos($document->content, 'First half of the meeting.');
    $secondPos = strpos($document->content, 'Second half of the meeting.');
    expect($firstPos)->not->toBeFalse()
        ->and($secondPos)->not->toBeFalse()
        ->and($firstPos)->toBeLessThan($secondPos);
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

// ── Recording-only meetings (recorded without Meet transcripts) ──────────────

/**
 * Fake a Meet conference that has no transcript but does have recordings, plus the Drive
 * download of its file. $tokens lets a test make the second (Drive-scoped) token request fail.
 *
 * @param  array<int, array<string, mixed>>  $recordings
 */
function fakeRecordingOnlyMeet(array $conference, array $recordings, ?\GuzzleHttp\Promise\PromiseInterface $driveResponse = null, ?\Illuminate\Http\Client\ResponseSequence $tokens = null): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => $tokens ?? Http::response(['access_token' => 'fake-google-token'], 200),
        'meet.googleapis.com/v2/*/transcripts*' => Http::response(['transcripts' => []], 200),
        'meet.googleapis.com/v2/*/recordings*' => Http::response(['recordings' => $recordings], 200),
        'meet.googleapis.com/v2/conferenceRecords*' => Http::response(['conferenceRecords' => [$conference]], 200),
        'www.googleapis.com/drive/v3/files/*' => $driveResponse ?? Http::response(fakeMeetMp4Bytes(), 200, ['Content-Type' => 'video/mp4']),
    ]);
}

function fakeMeetMp4Bytes(): string
{
    return "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isommp41".str_repeat("\x00", 64);
}

function generatedRecording(string $fileId, string $start = '2026-03-01T10:00:00Z', string $end = '2026-03-01T10:30:00Z'): array
{
    return [
        'name' => "conferenceRecords/rec-only/recordings/{$fileId}",
        'state' => 'FILE_GENERATED',
        'startTime' => $start,
        'endTime' => $end,
        'driveDestination' => ['file' => $fileId, 'exportUri' => "https://drive.google.com/file/d/{$fileId}/view"],
    ];
}

function recordingOnlyPlaceholder(Project $project, string $type = 'intake'): \App\Models\Document
{
    return $project->documents()->create([
        'type' => $type,
        'name' => 'Recorded Only',
        'content' => '',
        'processed_at' => now(),
        'metadata' => ['recording_id' => 'conferenceRecords/rec-only', 'provider' => 'google_meet'],
    ]);
}

it('lists a meeting that was recorded without a transcript, flagged as recording only', function () {
    fakeRecordingOnlyMeet(
        ['name' => 'conferenceRecords/rec-only', 'startTime' => '2026-03-01T10:00:00Z', 'endTime' => '2026-03-01T10:30:00Z'],
        [generatedRecording('file-1')],
    );

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('recordings', 1)
            ->where('recordings.0.id', 'conferenceRecords/rec-only')
            ->where('recordings.0.has_transcript', false)
        );
});

it('flags a meeting with a transcript as having one', function () {
    fakeGoogleMeetApi(conferences: [
        ['name' => 'conferenceRecords/abc123', 'startTime' => '2026-03-01T10:00:00Z', 'endTime' => '2026-03-01T10:30:00Z'],
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertInertia(fn ($page) => $page->where('recordings.0.has_transcript', true));
});

it('leaves out a recording whose file Meet hasn\'t generated yet', function () {
    fakeRecordingOnlyMeet(
        ['name' => 'conferenceRecords/rec-only', 'startTime' => '2026-03-01T10:00:00Z'],
        [array_merge(generatedRecording('file-1'), ['state' => 'ENDED'])],
    );

    $this->actingAs($this->admin)
        ->get(route('projects.transcripts.index', $this->project))
        ->assertInertia(fn ($page) => $page->where('recordings', []));
});

it('sends a recording-only meeting to be downloaded and transcribed instead of fetching a transcript', function () {
    Queue::fake();
    fakeRecordingOnlyMeet(['name' => 'conferenceRecords/rec-only'], [generatedRecording('file-1')]);

    $document = recordingOnlyPlaceholder($this->project);

    (new ImportMeetingTranscript($document, 'conferenceRecords/rec-only'))
        ->handle(app(\App\Services\MeetingTranscriptService::class));

    expect($document->fresh()->metadata['recording_source'])->toBe('provider_audio');
    Queue::assertPushedOn('recordings', \App\Jobs\DownloadMeetingRecording::class, fn ($job) => $job->document->is($document)
        && $job->recordingId === 'conferenceRecords/rec-only');
    Queue::assertNotPushed(ProcessDocumentAI::class);
});

it('downloads the recording from Drive, attaches it, and starts transcription', function () {
    Queue::fake();
    \Illuminate\Support\Facades\Storage::fake('local');
    fakeRecordingOnlyMeet(['name' => 'conferenceRecords/rec-only'], [generatedRecording('file-1')]);

    $document = recordingOnlyPlaceholder($this->project);

    app()->call([new \App\Jobs\DownloadMeetingRecording($document, 'conferenceRecords/rec-only'), 'handle']);

    $media = $document->fresh()->getFirstMedia('recording');
    expect($media)->not->toBeNull()
        ->and($media->mime_type)->toBe('video/mp4');
    expect(\Illuminate\Support\Facades\Storage::disk('local')->files('meeting-recordings'))->toBe([]);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files/file-1')
        && $request['alt'] === 'media');
    Queue::assertPushedOn('recordings', \App\Jobs\TranscribeRecording::class, fn ($job) => $job->document->is($document));
});

it('downloads the longest recording when recording was stopped and restarted', function () {
    Queue::fake();
    \Illuminate\Support\Facades\Storage::fake('local');
    fakeRecordingOnlyMeet(['name' => 'conferenceRecords/rec-only'], [
        generatedRecording('short', '2026-03-01T10:00:00Z', '2026-03-01T10:01:00Z'),
        generatedRecording('long', '2026-03-01T10:02:00Z', '2026-03-01T10:50:00Z'),
    ]);

    app()->call([new \App\Jobs\DownloadMeetingRecording(recordingOnlyPlaceholder($this->project), 'conferenceRecords/rec-only'), 'handle']);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files/long'));
    Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files/short'));
});

it('tells the user to add the Drive scope when the organization hasn\'t delegated it', function () {
    Queue::fake();
    \Illuminate\Support\Facades\Storage::fake('local');
    fakeRecordingOnlyMeet(
        ['name' => 'conferenceRecords/rec-only'],
        [generatedRecording('file-1')],
        tokens: Http::sequence()
            ->push(['access_token' => 'meet-token'], 200)
            ->push(['error' => 'unauthorized_client', 'error_description' => 'Client is unauthorized to retrieve access tokens using this method.'], 401),
    );

    $document = recordingOnlyPlaceholder($this->project);

    app()->call([new \App\Jobs\DownloadMeetingRecording($document, 'conferenceRecords/rec-only'), 'handle']);

    expect($document->fresh()->metadata['transcription_error'])
        ->toBe(\App\Services\Meeting\Drivers\GoogleMeetMeetingDriver::MISSING_DRIVE_SCOPE_MESSAGE);
    expect($document->fresh()->getFirstMedia('recording'))->toBeNull();
    Queue::assertNotPushed(\App\Jobs\TranscribeRecording::class);
});

it('says so when the recording file was deleted from Drive', function () {
    Queue::fake();
    \Illuminate\Support\Facades\Storage::fake('local');
    fakeRecordingOnlyMeet(['name' => 'conferenceRecords/rec-only'], [generatedRecording('file-1')], Http::response(['error' => ['code' => 404]], 404));

    $document = recordingOnlyPlaceholder($this->project);

    app()->call([new \App\Jobs\DownloadMeetingRecording($document, 'conferenceRecords/rec-only'), 'handle']);

    expect($document->fresh()->metadata['transcription_error'])->toContain('no longer in Google Drive');
    Queue::assertNotPushed(\App\Jobs\TranscribeRecording::class);
});

it('offers Try Again for a recording-only meeting whose download failed, and downloads it again', function () {
    Queue::fake();

    $document = recordingOnlyPlaceholder($this->project);
    $document->update(['metadata' => array_merge($document->metadata, [
        'recording_source' => 'provider_audio',
        'transcription_error' => \App\Services\Meeting\Drivers\GoogleMeetMeetingDriver::MISSING_DRIVE_SCOPE_MESSAGE,
    ])]);

    $recovery = app(\App\Services\RecordingRecoveryService::class);
    expect($recovery->problemFor($document->fresh()))->toMatchArray(['has_audio' => false, 'can_retry' => true]);

    expect($recovery->retry($document->fresh()))->toBeTrue();
    expect($document->fresh()->metadata)->not->toHaveKey('transcription_error');
    Queue::assertPushed(\App\Jobs\DownloadMeetingRecording::class, fn ($job) => $job->document->is($document));
});

it('saves a transcribed recording picked as a non-transcript type as finished content with no AI step', function () {
    Queue::fake();
    \Illuminate\Support\Facades\Storage::fake('local');

    $document = recordingOnlyPlaceholder($this->project, 'meeting_notes');
    $document->addMediaFromString(fakeMeetMp4Bytes())->usingFileName('meeting.mp4')->toMediaCollection('recording');
    $document->update(['metadata' => array_merge($document->metadata, ['transcription_id' => 't-1'])]);

    $driver = Mockery::mock(\App\Contracts\TranscriptionDriver::class);
    $driver->shouldReceive('poll')->with('t-1')->andReturn(['status' => 'completed', 'text' => 'We agreed to ship Friday.', 'error' => null]);

    (new \App\Jobs\TranscribeRecording($document))->handle($driver);

    $document->refresh();
    expect($document->content)->toBe('We agreed to ship Friday.')
        ->and($document->processed_at)->not->toBeNull();
    Queue::assertNotPushed(ProcessDocumentAI::class);
});

it('handles a recording-only meeting imported as a Status Meeting', function () {
    Queue::fake();
    \Illuminate\Support\Facades\Storage::fake('local');
    fakeRecordingOnlyMeet(['name' => 'conferenceRecords/rec-only'], [generatedRecording('file-1')]);

    $orgDocument = \App\Models\OrgDocument::create([
        'organization_id' => $this->org->id,
        'type' => 'status_meeting',
        'name' => 'Weekly Status',
        'content' => '',
        'processed_at' => now(),
        'metadata' => ['recording_id' => 'conferenceRecords/rec-only', 'provider' => 'google_meet'],
    ]);

    (new \App\Jobs\ImportOrgMeetingTranscript($orgDocument, 'conferenceRecords/rec-only'))
        ->handle(app(\App\Services\MeetingTranscriptService::class));

    Queue::assertPushedOn('recordings', \App\Jobs\DownloadMeetingRecording::class, fn ($job) => $job->document->is($orgDocument));

    app()->call([new \App\Jobs\DownloadMeetingRecording($orgDocument, 'conferenceRecords/rec-only'), 'handle']);
    expect($orgDocument->fresh()->getFirstMedia('recording'))->not->toBeNull();

    $orgDocument->update(['metadata' => array_merge($orgDocument->fresh()->metadata, ['transcription_id' => 't-2'])]);
    $driver = Mockery::mock(\App\Contracts\TranscriptionDriver::class);
    $driver->shouldReceive('poll')->with('t-2')->andReturn(['status' => 'completed', 'text' => 'Project A is on track.', 'error' => null]);

    (new \App\Jobs\TranscribeRecording($orgDocument->fresh()))->handle($driver);

    $orgDocument->refresh();
    expect($orgDocument->content)->toBe('Project A is on track.')
        ->and($orgDocument->processed_at)->toBeNull()
        ->and($orgDocument->metadata['ai_draft']['status'])->toBe('processing');
    Queue::assertPushed(\App\Jobs\ProcessOrgDocumentAI::class);
    Queue::assertPushed(\App\Jobs\GenerateOrgDocumentEmbedding::class);
});

it('refuses to download a recording-only meeting once the organization has used its transcription hours', function () {
    Queue::fake();
    fakeRecordingOnlyMeet(['name' => 'conferenceRecords/rec-only'], [generatedRecording('file-1')]);
    \App\Models\AiUsageLog::create([
        'organization_id' => $this->org->id,
        'driver' => 'assemblyai',
        'model' => 'universal-2',
        'type' => 'transcription',
        'audio_seconds' => 5 * 3600,
    ]);

    $document = recordingOnlyPlaceholder($this->project);

    app()->call([new \App\Jobs\DownloadMeetingRecording($document, 'conferenceRecords/rec-only'), 'handle']);

    expect($document->fresh()->metadata['transcription_error'])->toContain('used all 5 hours of recording transcription');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'drive/v3/files'));
    Queue::assertNotPushed(\App\Jobs\TranscribeRecording::class);
});
