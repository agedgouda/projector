<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org', 'membership_tier' => 'pro']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->otherClient = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);

    $this->parent = Project::create([
        'name' => 'Jimmy',
        'client_id' => $this->client->id,
    ]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    $this->client->users()->attach($this->admin->id);
    $this->otherClient->users()->attach($this->admin->id);
});

it('creates a sub-project when the parent belongs to the same client', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.store'), [
            'name' => 'Fleem Clavington',
            'client_id' => $this->client->id,
            'parent_id' => $this->parent->id,
        ])
        ->assertRedirect();

    $child = Project::where('name', 'Fleem Clavington')->firstOrFail();
    expect($child->parent_id)->toBe($this->parent->id);
});

it('rejects a parent project belonging to a different client', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.store'), [
            'name' => 'Fleem Clavington',
            'client_id' => $this->otherClient->id,
            'parent_id' => $this->parent->id,
        ])
        ->assertSessionHasErrors('parent_id');
});

it('rejects a project as its own parent', function () {
    $this->actingAs($this->admin)
        ->patch(route('projects.update', $this->parent), [
            'name' => $this->parent->name,
            'client_id' => $this->client->id,
            'parent_id' => $this->parent->id,
        ])
        ->assertSessionHasErrors('parent_id');
});

it('rejects nesting a sub-project under another sub-project', function () {
    $child = Project::create([
        'name' => 'Fleem Clavington',
        'client_id' => $this->client->id,
        'parent_id' => $this->parent->id,
    ]);

    $this->actingAs($this->admin)
        ->post(route('projects.store'), [
            'name' => 'Grandchild',
            'client_id' => $this->client->id,
            'parent_id' => $child->id,
        ])
        ->assertSessionHasErrors('parent_id');
});

it('blocks deleting a project that has sub-projects', function () {
    Project::create([
        'name' => 'Fleem Clavington',
        'client_id' => $this->client->id,
        'parent_id' => $this->parent->id,
    ]);

    $this->actingAs($this->admin)
        ->delete(route('projects.destroy', $this->parent))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Project::find($this->parent->id))->not->toBeNull();
});

it('allows deleting a project with no sub-projects', function () {
    $childless = Project::create([
        'name' => 'Standalone',
        'client_id' => $this->client->id,
    ]);

    $this->actingAs($this->admin)
        ->delete(route('projects.destroy', $childless))
        ->assertRedirect();

    expect(Project::find($childless->id))->toBeNull();
});

it('rejects assigning a parent to a project that already has sub-projects', function () {
    Project::create([
        'name' => 'Fleem Clavington',
        'client_id' => $this->client->id,
        'parent_id' => $this->parent->id,
    ]);

    $otherTopLevel = Project::create([
        'name' => 'Another Top-Level Project',
        'client_id' => $this->client->id,
    ]);

    $this->actingAs($this->admin)
        ->patch(route('projects.update', $this->parent), [
            'name' => $this->parent->name,
            'client_id' => $this->client->id,
            'parent_id' => $otherTopLevel->id,
        ])
        ->assertSessionHasErrors('parent_id');
});

it('allows changing an existing sub-project to a different valid parent, or clearing it', function () {
    $child = Project::create([
        'name' => 'Fleem Clavington',
        'client_id' => $this->client->id,
        'parent_id' => $this->parent->id,
    ]);

    $otherTopLevel = Project::create([
        'name' => 'Another Top-Level Project',
        'client_id' => $this->client->id,
    ]);

    $this->actingAs($this->admin)
        ->patch(route('projects.update', $child), [
            'name' => $child->name,
            'client_id' => $this->client->id,
            'parent_id' => $otherTopLevel->id,
        ])
        ->assertRedirect();

    expect($child->fresh()->parent_id)->toBe($otherTopLevel->id);

    $this->actingAs($this->admin)
        ->patch(route('projects.update', $child), [
            'name' => $child->name,
            'client_id' => $this->client->id,
            'parent_id' => null,
        ])
        ->assertRedirect();

    expect($child->fresh()->parent_id)->toBeNull();
});

// --- Logo inheritance ---

it('falls back to the parent project\'s logo when the sub-project has none of its own', function () {
    Storage::fake('public');

    $child = Project::create([
        'name' => 'Fleem Clavington',
        'client_id' => $this->client->id,
        'parent_id' => $this->parent->id,
    ]);

    expect($child->logo_url)->toBeNull();

    $this->parent->addMedia(UploadedFile::fake()->image('parent-logo.png', 200, 200))->toMediaCollection('logo');

    expect($child->fresh()->logo_url)->not->toBeNull()
        ->and($child->fresh()->logo_url)->toBe($this->parent->fresh()->logo_url);
});

it('uses its own logo instead of the parent\'s when the sub-project has one', function () {
    Storage::fake('public');

    $child = Project::create([
        'name' => 'Fleem Clavington',
        'client_id' => $this->client->id,
        'parent_id' => $this->parent->id,
    ]);

    $this->parent->addMedia(UploadedFile::fake()->image('parent-logo.png', 200, 200))->toMediaCollection('logo');
    $child->addMedia(UploadedFile::fake()->image('child-logo.png', 200, 200))->toMediaCollection('logo');

    expect($child->fresh()->logo_url)->not->toBe($this->parent->fresh()->logo_url);
});

it('does not fall back to anything for a top-level project with no logo', function () {
    expect($this->parent->logo_url)->toBeNull();
});

it('includes the inherited logo_url for a sub-project on the projects index page', function () {
    Storage::fake('public');

    $child = Project::create([
        'name' => 'Fleem Clavington',
        'client_id' => $this->client->id,
        'parent_id' => $this->parent->id,
    ]);

    $this->parent->addMedia(UploadedFile::fake()->image('parent-logo.png', 200, 200))->toMediaCollection('logo');

    $response = $this->actingAs($this->admin)->get(route('projects.index'));

    $response->assertOk();

    $projects = collect($response->original->getData()['page']['props']['projects']);
    $childData = $projects->firstWhere('id', $child->id);

    expect($childData['logo_url'] ?? null)->not->toBeNull();
});

it('preselects the parent project and locks the client on the create page', function () {
    $response = $this->actingAs($this->admin)
        ->get(route('projects.create', ['parent_project' => $this->parent->id]));

    $response->assertInertia(fn ($page) => $page
        ->component('Projects/Create')
        ->where('parentProject.id', $this->parent->id)
        ->where('preselectedClient.id', $this->client->id)
    );
});
