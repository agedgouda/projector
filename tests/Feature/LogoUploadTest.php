<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    setPermissionsTeamId($this->org->id);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
    ]);

    $projectType = ProjectType::create(['name' => 'Default', 'organization_id' => $this->org->id, 'document_schema' => []]);

    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
        'project_type_id' => $projectType->id,
    ]);

    $this->member = User::factory()->create();
    $this->org->users()->attach($this->member->id, ['role' => 'team-member']);
    $this->client->users()->attach($this->member->id);
});

// ── Organization ──────────────────────────────────────────────────────────────

it('allows an org-admin to upload a logo for an organization', function () {
    $file = UploadedFile::fake()->image('logo.png', 200, 200);

    $this->actingAs($this->admin)
        ->post(route('organizations.logo.store', $this->org), ['logo' => $file])
        ->assertRedirect();

    expect($this->org->fresh()->getFirstMedia('logo'))->not->toBeNull();
});

it('replaces the old logo when a new one is uploaded for an organization', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.logo.store', $this->org), ['logo' => UploadedFile::fake()->image('first.png')]);

    expect($this->org->fresh()->getMedia('logo'))->toHaveCount(1);

    $this->actingAs($this->admin)
        ->post(route('organizations.logo.store', $this->org), ['logo' => UploadedFile::fake()->image('second.png')]);

    expect($this->org->fresh()->getMedia('logo'))->toHaveCount(1);
});

it('allows an org-admin to delete a logo from an organization', function () {
    $this->actingAs($this->admin)
        ->post(route('organizations.logo.store', $this->org), ['logo' => UploadedFile::fake()->image('logo.png')]);

    $this->actingAs($this->admin)
        ->delete(route('organizations.logo.destroy', $this->org))
        ->assertRedirect();

    expect($this->org->fresh()->getFirstMedia('logo'))->toBeNull();
});

it('rejects non-image files for an organization logo', function () {
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    $this->actingAs($this->admin)
        ->post(route('organizations.logo.store', $this->org), ['logo' => $file])
        ->assertSessionHasErrors('logo');
});

it('rejects files over 5MB for an organization logo', function () {
    $file = UploadedFile::fake()->image('big.png')->size(6000);

    $this->actingAs($this->admin)
        ->post(route('organizations.logo.store', $this->org), ['logo' => $file])
        ->assertSessionHasErrors('logo');
});

it('blocks a non-admin from uploading a logo for an organization', function () {
    $file = UploadedFile::fake()->image('logo.png');

    $this->actingAs($this->member)
        ->post(route('organizations.logo.store', $this->org), ['logo' => $file])
        ->assertNotFound();
});

// ── Client ────────────────────────────────────────────────────────────────────

it('allows an org-admin to upload a logo for a client', function () {
    $file = UploadedFile::fake()->image('client-logo.jpg', 200, 200);

    $this->actingAs($this->admin)
        ->post(route('clients.logo.store', $this->client), ['logo' => $file])
        ->assertRedirect();

    expect($this->client->fresh()->getFirstMedia('logo'))->not->toBeNull();
});

it('replaces the old logo when a new one is uploaded for a client', function () {
    $this->actingAs($this->admin)
        ->post(route('clients.logo.store', $this->client), ['logo' => UploadedFile::fake()->image('first.jpg')]);

    expect($this->client->fresh()->getMedia('logo'))->toHaveCount(1);

    $this->actingAs($this->admin)
        ->post(route('clients.logo.store', $this->client), ['logo' => UploadedFile::fake()->image('second.jpg')]);

    expect($this->client->fresh()->getMedia('logo'))->toHaveCount(1);
});

it('allows an org-admin to delete a logo from a client', function () {
    $this->actingAs($this->admin)
        ->post(route('clients.logo.store', $this->client), ['logo' => UploadedFile::fake()->image('logo.jpg')]);

    $this->actingAs($this->admin)
        ->delete(route('clients.logo.destroy', $this->client))
        ->assertRedirect();

    expect($this->client->fresh()->getFirstMedia('logo'))->toBeNull();
});

it('rejects non-image files for a client logo', function () {
    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->actingAs($this->admin)
        ->post(route('clients.logo.store', $this->client), ['logo' => $file])
        ->assertSessionHasErrors('logo');
});

it('blocks a non-admin from uploading a logo for a client', function () {
    $file = UploadedFile::fake()->image('logo.png');

    $this->actingAs($this->member)
        ->post(route('clients.logo.store', $this->client), ['logo' => $file])
        ->assertNotFound();
});

// ── Project ───────────────────────────────────────────────────────────────────

it('allows an org-admin to upload a logo for a project', function () {
    $file = UploadedFile::fake()->image('project-logo.png', 200, 200);

    $this->actingAs($this->admin)
        ->post(route('projects.logo.store', $this->project), ['logo' => $file])
        ->assertRedirect();

    expect($this->project->fresh()->getFirstMedia('logo'))->not->toBeNull();
});

it('replaces the old logo when a new one is uploaded for a project', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.logo.store', $this->project), ['logo' => UploadedFile::fake()->image('first.png')]);

    expect($this->project->fresh()->getMedia('logo'))->toHaveCount(1);

    $this->actingAs($this->admin)
        ->post(route('projects.logo.store', $this->project), ['logo' => UploadedFile::fake()->image('second.png')]);

    expect($this->project->fresh()->getMedia('logo'))->toHaveCount(1);
});

it('allows an org-admin to delete a logo from a project', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.logo.store', $this->project), ['logo' => UploadedFile::fake()->image('logo.png')]);

    $this->actingAs($this->admin)
        ->delete(route('projects.logo.destroy', $this->project))
        ->assertRedirect();

    expect($this->project->fresh()->getFirstMedia('logo'))->toBeNull();
});

it('rejects non-image files for a project logo', function () {
    $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

    $this->actingAs($this->admin)
        ->post(route('projects.logo.store', $this->project), ['logo' => $file])
        ->assertSessionHasErrors('logo');
});

it('blocks a non-admin from uploading a logo for a project', function () {
    $file = UploadedFile::fake()->image('logo.png');

    $this->actingAs($this->member)
        ->post(route('projects.logo.store', $this->project), ['logo' => $file])
        ->assertNotFound();
});

// ── Create with logo ──────────────────────────────────────────────────────────

it('stores a logo when creating an organization', function () {
    $file = UploadedFile::fake()->image('new-org-logo.png', 200, 200);

    $this->actingAs($this->admin)
        ->post(route('organizations.store'), [
            'name' => 'Logo Org',
            'logo' => $file,
        ])
        ->assertRedirect();

    $org = \App\Models\Organization::where('name', 'Logo Org')->firstOrFail();
    expect($org->getFirstMedia('logo'))->not->toBeNull();
});

it('stores a logo when creating a client', function () {
    // Use a pro-tier org so the membership limit does not block creation
    $proOrg = Organization::create(['name' => 'Pro Org', 'membership_tier' => 'pro']);
    $proOrg->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($proOrg->id);

    $file = UploadedFile::fake()->image('new-client-logo.png', 200, 200);

    $this->actingAs($this->admin)
        ->withSession(['active_org_id' => $proOrg->id])
        ->post(route('clients.store'), [
            'company_name' => 'Logo Client',
            'contact_name' => 'Test Contact',
            'email' => 'logo@client.test',
            'logo' => $file,
        ])
        ->assertRedirect();

    $client = \App\Models\Client::where('company_name', 'Logo Client')->firstOrFail();
    expect($client->getFirstMedia('logo'))->not->toBeNull();
});

it('stores a logo when creating a project', function () {
    // Use a pro-tier org so the membership limit does not block creation
    $proOrg = Organization::create(['name' => 'Pro Org 2', 'membership_tier' => 'pro']);
    $proOrg->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($proOrg->id);

    $proClient = \App\Models\Client::create([
        'organization_id' => $proOrg->id,
        'company_name' => 'Pro Client',
        'contact_name' => 'Test',
    ]);

    $projectType = \App\Models\ProjectType::create([
        'name' => 'Default',
        'organization_id' => $proOrg->id,
        'document_schema' => [],
    ]);

    $file = UploadedFile::fake()->image('new-project-logo.png', 200, 200);

    $this->actingAs($this->admin)
        ->withSession(['active_org_id' => $proOrg->id])
        ->post(route('projects.store'), [
            'name' => 'Logo Project',
            'client_id' => $proClient->id,
            'project_type_id' => $projectType->id,
            'logo' => $file,
        ])
        ->assertRedirect();

    $project = \App\Models\Project::where('name', 'Logo Project')->firstOrFail();
    expect($project->getFirstMedia('logo'))->not->toBeNull();
});
