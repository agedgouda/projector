<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// QUEUE_CONNECTION=sync in testing means creating an intake document without this would let
// DocumentObserver's real dispatched AI job run synchronously and touch processed_at itself —
// exactly the field these tests are asserting on.
beforeEach(fn () => Queue::fake());

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
    ]);
});

it('returns the user\'s own processing documents with no project/client route param and no org cookie set', function () {
    // Deliberately no ->withSession(['active_org_id' => ...]) and no {project}/{client} route
    // param — this is exactly the request shape SetOrganizationContext can't resolve an org
    // for via anything but its cookie fallback, which this test doesn't provide either. The
    // old implementation 403'd here; this is the whole bug being fixed.
    $processing = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Still processing',
        'content' => '',
    ]);
    $done = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Already done',
        'content' => 'finished',
        'processed_at' => now(),
    ]);

    $response = $this->actingAs($this->user)->getJson(route('processing-status'));

    $response->assertOk();
    expect($response->json('processing_document_ids'))->toContain($processing->id);
    expect($response->json('processing_document_ids'))->not->toContain($done->id);
});

it('excludes documents in a project the user cannot see', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $otherProject = Project::create(['name' => 'Other Project', 'client_id' => $otherClient->id]);
    $otherProject->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Not mine',
        'content' => '',
    ]);

    $response = $this->actingAs($this->user)->getJson(route('processing-status'));

    $response->assertOk();
    expect($response->json('processing_document_ids'))->toBe([]);
});

it('excludes document types that never go through AI processing', function () {
    $event = $this->project->documents()->create([
        'type' => 'event',
        'name' => 'An event',
        'content' => '',
    ]);

    $response = $this->actingAs($this->user)->getJson(route('processing-status'));

    $response->assertOk();
    expect($response->json('processing_document_ids'))->not->toContain($event->id);
});

it('rejects an unauthenticated request', function () {
    $this->getJson(route('processing-status'))->assertUnauthorized();
});
