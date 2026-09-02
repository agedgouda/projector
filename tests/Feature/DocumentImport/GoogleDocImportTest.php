<?php

use App\Jobs\ImportMeetingTranscript;
use App\Jobs\ProcessDocumentAI;
use App\Models\Client;
use App\Models\Document;
use App\Models\GoogleOauthToken;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    $this->org = Organization::create(['name' => 'Test Org']);
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

it('returns 428 with a connect url when fetching a picker token without a connected google account', function () {
    $response = $this->actingAs($this->admin)
        ->getJson(route('projects.transcripts.google-picker-token', $this->project));

    $response->assertStatus(428);
    expect($response->json('connect_url'))->toBe(route('integrations.google.connect'));
});

it('returns an access token for the picker when connected', function () {
    GoogleOauthToken::factory()->create([
        'user_id' => $this->admin->id,
        'access_token' => 'fake-picker-token',
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson(route('projects.transcripts.google-picker-token', $this->project));

    $response->assertOk();
    expect($response->json('access_token'))->toBe('fake-picker-token');
});

it('creates an intake document from a picked google doc, using the picker-supplied title', function () {
    Queue::fake();

    GoogleOauthToken::factory()->create([
        'user_id' => $this->admin->id,
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'www.googleapis.com/drive/v3/files/*/export*' => Http::response('<p>Meeting notes here.</p>', 200),
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-google-doc', $this->project), [
            'file_id' => 'doc-abc123',
            'title' => 'Weekly Sync Notes',
            'custom_prompt' => 'Summarize concisely.',
            'type' => config('workflow.intake_key'),
        ])
        ->assertRedirect();

    $document = $this->project->documents()->where('type', config('workflow.intake_key'))->first();

    // Content isn't filled in synchronously any more — a Transcription-type import now goes
    // through the same ImportMeetingTranscript pipeline a picked recording uses, so the
    // already-extracted html travels as that queued job's payload instead.
    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Weekly Sync Notes')
        ->and($document->content)->toBe('')
        ->and($document->custom_prompt)->toBe('Summarize concisely.')
        ->and($document->metadata['import_source'])->toBe('google_doc')
        ->and($document->metadata['google_file_id'])->toBe('doc-abc123');

    Queue::assertPushed(ImportMeetingTranscript::class, fn ($job) => $job->document->is($document)
        && $job->recordingId === null
        && str_contains($job->content ?? '', 'Meeting notes here.'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'doc-abc123/export')
        && $request['mimeType'] === 'text/html');
});

it('redirects to a pre-created blank Meeting Notes document, same as a picked recording, when imported as Transcription', function () {
    Queue::fake();

    $template = \App\Models\AiTemplate::create([
        'name' => 'Transcript to Meeting Notes',
        'type' => 'workflow',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
        'single_output' => true,
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $template->id]);

    GoogleOauthToken::factory()->create([
        'user_id' => $this->admin->id,
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'www.googleapis.com/drive/v3/files/*/export*' => Http::response('<p>Meeting notes here.</p>', 200),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-google-doc', $this->project), [
            'file_id' => 'doc-abc123',
            'title' => 'Weekly Sync Notes',
            'type' => config('workflow.intake_key'),
        ]);

    $intake = $this->project->documents()->where('type', config('workflow.intake_key'))->firstOrFail();
    $meetingNotes = $this->project->documents()->where('parent_id', $intake->id)->firstOrFail();

    expect($meetingNotes->type)->toBe(config('workflow.action_items_key'))
        ->and($meetingNotes->name)->toBe('Weekly Sync Notes')
        ->and($meetingNotes->content)->toBe('');

    $response->assertRedirect(route('projects.documents.show', [$this->project, $meetingNotes]));
});

it('creates the document as the picked type and skips AI processing when it is not the intake type', function () {
    Queue::fake();

    // resolveDocumentType() validates a picked (non-new, non-intake) type against types
    // actually already in use in this project, not a separate catalog — so there needs to be
    // one already for this to be a valid choice.
    $this->project->documents()->create([
        'type' => config('workflow.action_items_key'),
        'name' => 'Existing Meeting Notes',
        'content' => 'Pre-existing.',
        'processed_at' => now(),
    ]);

    GoogleOauthToken::factory()->create([
        'user_id' => $this->admin->id,
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'www.googleapis.com/drive/v3/files/*/export*' => Http::response('<p>Already-finished notes.</p>', 200),
    ]);

    $response = $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-google-doc', $this->project), [
            'file_id' => 'doc-abc123',
            'title' => 'Finished Notes',
            'custom_prompt' => 'This should be ignored.',
            'type' => config('workflow.action_items_key'),
        ]);

    $document = $this->project->documents()->where('name', 'Finished Notes')->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Finished Notes')
        ->and($document->content)->toContain('Already-finished notes.')
        ->and($document->custom_prompt)->toBeNull()
        ->and($document->processed_at)->not->toBeNull();

    // Sent straight to the new document's own page, same as the intake branch — not back to
    // the tab it was imported from.
    $response->assertRedirect(route('projects.documents.show', [$this->project, $document]));

    Queue::assertNotPushed(ProcessDocumentAI::class);
});

it('creates a new org-scoped document type and uses it when new_type_label is given', function () {
    Queue::fake();

    GoogleOauthToken::factory()->create([
        'user_id' => $this->admin->id,
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'www.googleapis.com/drive/v3/files/*/export*' => Http::response('<p>Design brief content.</p>', 200),
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-google-doc', $this->project), [
            'file_id' => 'doc-abc123',
            'title' => 'Design Brief',
            'new_type_label' => 'Design Brief',
        ])
        ->assertRedirect();

    $definition = \App\Models\DocumentTypeDefinition::where('organization_id', $this->org->id)
        ->where('key', 'design_brief')
        ->first();

    expect($definition)->not->toBeNull()
        ->and($definition->label)->toBe('Design Brief')
        ->and($definition->is_task)->toBeFalse();

    $document = $this->project->documents()->where('type', 'design_brief')->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Design Brief')
        ->and($document->content)->toContain('Design brief content.')
        ->and($document->processed_at)->not->toBeNull();

    Queue::assertNotPushed(ProcessDocumentAI::class);
});

it('rejects a type that is not in the organization\'s document type catalog', function () {
    GoogleOauthToken::factory()->create([
        'user_id' => $this->admin->id,
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'www.googleapis.com/drive/v3/files/*/export*' => Http::response('<p>Content.</p>', 200),
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-google-doc', $this->project), [
            'file_id' => 'doc-abc123',
            'title' => 'Weekly Sync Notes',
            'type' => 'not-a-real-type',
        ])
        ->assertStatus(422);

    expect(Document::count())->toBe(0);
});

it('forbids importing a google doc for a user without transcript-management rights', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);

    GoogleOauthToken::factory()->create([
        'user_id' => $member->id,
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($member)
        ->post(route('projects.transcripts.import-google-doc', $this->project), [
            'file_id' => 'doc-abc123',
            'title' => 'Weekly Sync Notes',
        ])
        ->assertForbidden();

    expect(Document::count())->toBe(0);
});

it('surfaces an error when the google doc export request fails', function () {
    GoogleOauthToken::factory()->create([
        'user_id' => $this->admin->id,
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'www.googleapis.com/drive/v3/files/*/export*' => Http::response(['error' => 'not found'], 404),
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-google-doc', $this->project), [
            'file_id' => 'doc-missing',
            'title' => 'Weekly Sync Notes',
        ])->assertStatus(500);

    expect(Document::count())->toBe(0);
});
