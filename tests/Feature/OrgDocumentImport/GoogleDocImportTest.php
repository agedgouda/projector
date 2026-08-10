<?php

use App\Jobs\ProcessOrgDocumentAI;
use App\Models\GoogleOauthToken;
use App\Models\Organization;
use App\Models\OrgDocument;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    $this->org = Organization::create(['name' => 'Test Org']);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

it('returns 428 with a connect url when fetching a picker token without a connected google account', function () {
    $response = $this->actingAs($this->admin)
        ->getJson(route('organizations.google-picker-token', $this->org));

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
        ->getJson(route('organizations.google-picker-token', $this->org));

    $response->assertOk();
    expect($response->json('access_token'))->toBe('fake-picker-token');
});

it('creates a status meeting from a picked google doc, using the picker-supplied title', function () {
    Queue::fake();

    GoogleOauthToken::factory()->create([
        'user_id' => $this->admin->id,
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'www.googleapis.com/drive/v3/files/*/export*' => Http::response('<p>Meeting notes here.</p>', 200),
    ]);

    $this->actingAs($this->admin)
        ->post(route('organizations.import-google-doc', $this->org), [
            'file_id' => 'doc-abc123',
            'title' => 'Weekly Sync Notes',
            'custom_prompt' => 'Summarize concisely.',
        ])
        ->assertRedirect();

    $orgDocument = $this->org->orgDocuments()->where('type', 'status_meeting')->first();

    expect($orgDocument)->not->toBeNull()
        ->and($orgDocument->name)->toBe('Weekly Sync Notes')
        ->and($orgDocument->content)->toContain('Meeting notes here.')
        ->and($orgDocument->custom_prompt)->toBe('Summarize concisely.')
        ->and($orgDocument->metadata['import_source'])->toBe('google_doc')
        ->and($orgDocument->metadata['google_file_id'])->toBe('doc-abc123')
        ->and($orgDocument->metadata['ai_draft']['status'])->toBe('processing');

    Queue::assertPushed(ProcessOrgDocumentAI::class, fn ($job) => $job->orgDocument->is($orgDocument));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'doc-abc123/export')
        && $request['mimeType'] === 'text/html');
});

it('forbids importing a google doc for a user without org-admin rights', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);

    GoogleOauthToken::factory()->create([
        'user_id' => $member->id,
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($member)
        ->post(route('organizations.import-google-doc', $this->org), [
            'file_id' => 'doc-abc123',
            'title' => 'Weekly Sync Notes',
        ])
        ->assertNotFound();

    expect(OrgDocument::count())->toBe(0);
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
        ->post(route('organizations.import-google-doc', $this->org), [
            'file_id' => 'doc-missing',
            'title' => 'Weekly Sync Notes',
        ])->assertStatus(500);

    expect(OrgDocument::count())->toBe(0);
});
