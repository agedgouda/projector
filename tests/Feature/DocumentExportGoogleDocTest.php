<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\GoogleOauthToken;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');

    ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Action Items', 'key' => 'action_items', 'is_task' => true],
        ],
    ]);

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

    setPermissionsTeamId($this->org->id);
});

it('returns 428 with a connect url when exporting without a connected google account', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items for Kickoff',
        'type' => 'action_items',
        'content' => '<p>Follow up with the client.</p>',
        'processed_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson(route('projects.documents.exportGoogleDoc', [$this->project, $document]));

    $response->assertStatus(428);
    expect($response->json('connect_url'))->toBe(route('integrations.google.connect'));
});

it('creates a google doc for the document when connected', function () {
    GoogleOauthToken::factory()->create([
        'user_id' => $this->admin->id,
        'expires_at' => now()->addHour(),
    ]);

    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items for Kickoff',
        'type' => 'action_items',
        'content' => '<p>Follow up with the client.</p>',
        'priority' => 'high',
        'processed_at' => now(),
    ]);

    Http::fake([
        'sheets.googleapis.com/*' => Http::response([], 200),
        'docs.googleapis.com/v1/documents' => Http::response(['documentId' => 'doc123'], 200),
        'docs.googleapis.com/v1/documents/*:batchUpdate' => Http::response([], 200),
        'docs.googleapis.com/v1/documents/*' => Http::response([
            'body' => ['content' => []],
        ], 200),
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson(route('projects.documents.exportGoogleDoc', [$this->project, $document]));

    $response->assertOk();
    expect($response->json('url'))->toBe('https://docs.google.com/document/d/doc123/edit');

    Http::assertSent(fn ($request) => $request->url() === 'https://docs.googleapis.com/v1/documents'
        && $request['title'] === 'action-items-for-kickoff');
});

it('denies access to a document outside the user\'s organization', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Private Notes',
        'type' => 'action_items',
        'content' => 'Secret content',
        'processed_at' => now(),
    ]);

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->getJson(route('projects.documents.exportGoogleDoc', [$this->project, $document]))
        ->assertNotFound();
});

it('returns 404 when the document does not belong to the given project', function () {
    $otherProject = Project::create([
        'name' => 'Other Project',
        'client_id' => $this->client->id,
    ]);

    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Some content',
        'processed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->getJson(route('projects.documents.exportGoogleDoc', [$otherProject, $document]))
        ->assertNotFound();
});

it('redirects guests to login', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Some content',
        'processed_at' => now(),
    ]);

    $this->get(route('projects.documents.exportGoogleDoc', [$this->project, $document]))
        ->assertRedirect();
});
