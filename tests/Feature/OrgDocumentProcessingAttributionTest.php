<?php

use App\Jobs\GenerateOrgDocumentEmbedding;
use App\Jobs\ProcessOrgDocumentAI;
use App\Models\Organization;
use App\Models\OrgDocument;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([GenerateOrgDocumentEmbedding::class, ProcessOrgDocumentAI::class]);

    setPermissionsTeamId(null);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
});

it('stamps the acting user as processing_triggered_by_user_id when store() kicks off processing', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.documents.store', $this->org), [
            'name' => 'Weekly Sync',
            'type' => 'status_meeting',
            'content' => 'Some transcript content',
        ])
        ->assertRedirect();

    $orgDocument = OrgDocument::where('organization_id', $this->org->id)->firstOrFail();

    expect($orgDocument->processing_triggered_by_user_id)->toBe($this->admin->id);
});

it('stamps the acting user as processing_triggered_by_user_id on processDraft()', function () {
    $orgDocument = OrgDocument::create([
        'organization_id' => $this->org->id,
        'name' => 'Weekly Sync',
        'type' => 'status_meeting',
        'content' => 'Some transcript content',
        'metadata' => ['ai_draft' => ['status' => 'failed', 'error' => 'boom']],
    ]);

    $other = User::factory()->create();
    $this->org->users()->attach($other->id, ['role' => 'org-admin']);

    $this->actingAs($other)
        ->post(route('organizations.documents.process-draft', [$this->org, $orgDocument]))
        ->assertRedirect();

    expect($orgDocument->fresh()->processing_triggered_by_user_id)->toBe($other->id);
});
