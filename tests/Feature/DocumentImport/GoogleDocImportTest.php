<?php

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
        ])
        ->assertRedirect();

    $document = $this->project->documents()->where('type', config('workflow.intake_key'))->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Weekly Sync Notes')
        ->and($document->content)->toContain('Meeting notes here.')
        ->and($document->custom_prompt)->toBe('Summarize concisely.')
        ->and($document->metadata['import_source'])->toBe('google_doc')
        ->and($document->metadata['google_file_id'])->toBe('doc-abc123');

    Queue::assertPushed(ProcessDocumentAI::class, fn ($job) => $job->document->is($document));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'doc-abc123/export')
        && $request['mimeType'] === 'text/html');
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
