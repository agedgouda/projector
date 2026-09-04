<?php

use App\Models\Client;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    $this->member = User::factory()->create();
    $this->org->users()->attach($this->member->id, ['role' => 'member']);
    $this->client->users()->attach($this->member->id);

    setPermissionsTeamId($this->org->id);
});

// ── index() ──────────────────────────────────────────────────────────────────────

it('lists projects the user can manage imports for', function () {
    $response = $this->actingAs($this->admin)->get(route('import.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Import/Wizard', false)
        ->has('projects', 1)
        ->where('projects.0.id', $this->project->id)
        ->where('projects.0.name', 'Test Project')
        ->has('googlePickerConfigured')
    );
});

it('excludes projects for a member who is not an org-admin or project-lead', function () {
    $response = $this->actingAs($this->member)->get(route('import.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Import/Wizard', false)
        ->has('projects', 0)
    );
});

it('excludes inactive projects', function () {
    $this->project->update(['inactive' => true]);

    $response = $this->actingAs($this->admin)->get(route('import.index'));

    $response->assertInertia(fn ($page) => $page->has('projects', 0));
});

it('excludes projects belonging to an inactive client', function () {
    $this->client->update(['inactive' => true]);

    $response = $this->actingAs($this->admin)->get(route('import.index'));

    $response->assertInertia(fn ($page) => $page->has('projects', 0));
});

it('lists projects for a super-admin regardless of org membership', function () {
    $superAdmin = User::factory()->create();
    setPermissionsTeamId(null);
    $superAdmin->assignRole('super-admin');
    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($superAdmin)->get(route('import.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Import/Wizard', false)
        ->has('projects', 1)
        ->where('projects.0.id', $this->project->id)
    );
});

it('requires authentication', function () {
    $response = $this->get(route('import.index'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith(route('login'));
});

// ── projectContext() ─────────────────────────────────────────────────────────────

it('returns the bootstrap data an import needs for a manageable project', function () {
    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);
    $intake = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Some Notes',
        'content' => 'hello',
    ]);

    $response = $this->actingAs($this->admin)
        ->getJson(route('projects.import-wizard-context', $this->project));

    $response->assertOk();
    $response->assertJson([
        'canManage' => true,
        'meetingProvider' => null,
    ]);
    expect($response->json('documentTypeCatalog'))->toBeArray();
    expect($response->json('documents'))->toHaveCount(1);
    expect($response->json('documents.0'))->toBe([
        'id' => $intake->id,
        'type' => config('workflow.intake_key'),
        'parent_id' => null,
    ]);
});

it('reports the organization meeting provider when configured', function () {
    $this->org->update(['meeting_provider' => 'zoom']);

    $response = $this->actingAs($this->admin)
        ->getJson(route('projects.import-wizard-context', $this->project));

    $response->assertJson(['meetingProvider' => 'zoom']);
});

it('forbids a member who is not an org-admin or project-lead from fetching import context', function () {
    $response = $this->actingAs($this->member)
        ->getJson(route('projects.import-wizard-context', $this->project));

    $response->assertForbidden();
});

it('allows a project-lead to fetch import context', function () {
    $lead = User::factory()->create();
    $this->org->users()->attach($lead->id, ['role' => 'project-lead']);

    $response = $this->actingAs($lead)
        ->getJson(route('projects.import-wizard-context', $this->project));

    $response->assertOk();
    $response->assertJson(['canManage' => true]);
});
