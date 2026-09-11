<?php

use App\Jobs\GenerateDocumentEmbedding;
use App\Jobs\GenerateOrgDocumentEmbedding;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\OrgDocument;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([GenerateDocumentEmbedding::class, GenerateOrgDocumentEmbedding::class]);

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Acme Corp',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Website Redesign',
        'client_id' => $this->client->id,
    ]);

    $this->member = User::factory()->create();
    $this->org->users()->attach($this->member->id, ['role' => 'org-admin']);

    $this->outsider = User::factory()->create();
});

it('redirects guests', function () {
    $this->get('/processing-status')
        ->assertRedirectContains('login');
});

it('rejects a user who is not a member of the active org', function () {
    $this->actingAs($this->outsider)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson('/processing-status')
        ->assertForbidden();
});

it('lists a document as processing while processed_at is null', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Meeting Notes',
        'type' => 'meeting_notes',
        'content' => 'Notes content',
        'processed_at' => null,
    ]);

    $response = $this->actingAs($this->member)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson('/processing-status')
        ->assertSuccessful();

    expect($response->json('processing_document_ids'))->toContain($document->id);
});

it('omits a document once processed_at is set', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Meeting Notes',
        'type' => 'meeting_notes',
        'content' => 'Notes content',
        'processed_at' => now(),
    ]);

    $response = $this->actingAs($this->member)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson('/processing-status')
        ->assertSuccessful();

    expect($response->json('processing_document_ids'))->not->toContain($document->id);
});

it('lists an org document as processing only while its ai_draft status is processing', function (?string $status, bool $expected) {
    $orgDocument = OrgDocument::create([
        'organization_id' => $this->org->id,
        'name' => 'Weekly Sync',
        'type' => 'status_meeting',
        'content' => 'Transcript content',
        'metadata' => $status === null ? [] : ['ai_draft' => ['status' => $status]],
    ]);

    $response = $this->actingAs($this->member)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson('/processing-status')
        ->assertSuccessful();

    $ids = $response->json('processing_org_document_ids');

    expect(in_array($orgDocument->id, $ids, true))->toBe($expected);
})->with([
    'processing' => ['processing', true],
    'pending_review' => ['pending_review', false],
    'committed' => ['committed', false],
    'no draft yet' => [null, false],
]);

it('excludes document types whose processed_at is never meaningfully set', function (string $type) {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Never processed',
        'type' => $type,
        'content' => '',
        'processed_at' => null,
    ]);

    $response = $this->actingAs($this->member)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson('/processing-status')
        ->assertSuccessful();

    expect($response->json('processing_document_ids'))->not->toContain($document->id);
})->with([
    'event' => ['event'],
    'task_list_import' => ['task_list_import'],
    'event_list_import' => ['event_list_import'],
]);

it('does not leak ids belonging to another organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Co',
        'contact_name' => 'John Roe',
        'contact_phone' => '555-6789',
    ]);
    $otherProject = Project::create([
        'name' => 'Other Project',
        'client_id' => $otherClient->id,
    ]);
    $otherDocument = Document::create([
        'project_id' => $otherProject->id,
        'name' => 'Other Notes',
        'type' => 'meeting_notes',
        'content' => 'Notes content',
        'processed_at' => null,
    ]);

    $response = $this->actingAs($this->member)
        ->withSession(['active_org_id' => $this->org->id])
        ->getJson('/processing-status')
        ->assertSuccessful();

    expect($response->json('processing_document_ids'))->not->toContain($otherDocument->id);
});
