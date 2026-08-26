<?php

use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

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
    $this->child = Project::create([
        'name' => 'Sub Project',
        'client_id' => $this->client->id,
        'parent_id' => $this->project->id,
    ]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    $this->lead = User::factory()->create();
    $this->org->users()->attach($this->lead->id, ['role' => 'project-lead']);

    $this->member = User::factory()->create();
    $this->org->users()->attach($this->member->id, ['role' => 'team-member']);

    setPermissionsTeamId($this->org->id);
});

it('creates a category with the chosen color, not auto-rotated', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.categories.store', $this->project), ['name' => 'Design', 'color' => 'pink'])
        ->assertRedirect();

    $category = Category::where('project_id', $this->project->id)->latest()->first();

    expect($category->name)->toBe('Design');
    expect($category->color)->toBe('pink');
});

it('rejects a color outside the 10-color palette', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.categories.store', $this->project), ['name' => 'Design', 'color' => 'chartreuse'])
        ->assertSessionHasErrors('color');

    expect(Category::where('project_id', $this->project->id)->count())->toBe(0);
});

it('allows a project-lead to create, update, and delete a category', function () {
    $this->actingAs($this->lead)
        ->post(route('projects.categories.store', $this->project), ['name' => 'Backend', 'color' => 'blue'])
        ->assertRedirect();

    $category = Category::where('project_id', $this->project->id)->latest()->first();

    $this->actingAs($this->lead)
        ->patch(route('projects.categories.update', [$this->project, $category]), ['name' => 'Server'])
        ->assertRedirect();

    expect($category->fresh()->name)->toBe('Server');

    $this->actingAs($this->lead)
        ->delete(route('projects.categories.destroy', [$this->project, $category]))
        ->assertRedirect();

    expect(Category::find($category->id))->toBeNull();
});

it('blocks a plain member from creating, updating, or deleting a category', function () {
    $category = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);

    $this->actingAs($this->member)
        ->post(route('projects.categories.store', $this->project), ['name' => 'Blocked', 'color' => 'red'])
        ->assertNotFound();

    $this->actingAs($this->member)
        ->patch(route('projects.categories.update', [$this->project, $category]), ['name' => 'Hacked'])
        ->assertNotFound();

    $this->actingAs($this->member)
        ->delete(route('projects.categories.destroy', [$this->project, $category]))
        ->assertNotFound();
});

it('creates the category under the family root when posted via a subproject', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.categories.store', $this->child), ['name' => 'Design', 'color' => 'pink'])
        ->assertRedirect();

    $category = Category::latest()->first();

    expect($category->project_id)->toBe($this->project->id);
});

it('rejects updating or deleting a category via a project outside its family', function () {
    $otherProject = Project::create(['name' => 'Unrelated Project', 'client_id' => $this->client->id]);
    $category = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);

    $this->actingAs($this->admin)
        ->patch(route('projects.categories.update', [$otherProject, $category]), ['name' => 'Hacked'])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete(route('projects.categories.destroy', [$otherProject, $category]))
        ->assertNotFound();

    expect($category->fresh()->name)->toBe('Design');
});

it('lists the family category set via the index endpoint regardless of which side of the family is queried', function () {
    Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);

    $fromRoot = $this->actingAs($this->admin)->getJson(route('projects.categories.index', $this->project));
    $fromChild = $this->actingAs($this->admin)->getJson(route('projects.categories.index', $this->child));

    $fromRoot->assertOk()->assertJsonFragment(['name' => 'Design']);
    $fromChild->assertOk()->assertJsonFragment(['name' => 'Design']);
});

it('syncs a document\'s tags to the given set', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $backend = Category::create(['project_id' => $this->project->id, 'name' => 'Backend', 'color' => 'blue']);
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateCategories', [$this->project, $document]), [
            'category_ids' => [$design->id, $backend->id],
        ])
        ->assertRedirect();

    expect($document->fresh()->categories()->pluck('categories.id')->all())
        ->toEqualCanonicalizing([$design->id, $backend->id]);
});

it('allows an event document exactly one tag', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'An event',
        'type' => 'event',
        'content' => 'content',
    ]);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateCategories', [$this->project, $document]), [
            'category_ids' => [$design->id],
        ])
        ->assertRedirect();

    expect($document->fresh()->categories()->pluck('categories.id')->all())->toBe([$design->id]);
});

it('rejects assigning an event document a second tag', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $backend = Category::create(['project_id' => $this->project->id, 'name' => 'Backend', 'color' => 'blue']);
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'An event',
        'type' => 'event',
        'content' => 'content',
    ]);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateCategories', [$this->project, $document]), [
            'category_ids' => [$design->id, $backend->id],
        ])
        ->assertSessionHasErrors('category_ids');

    expect($document->fresh()->categories()->count())->toBe(0);
});

it('removes tags no longer present when syncing a document\'s tags', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $backend = Category::create(['project_id' => $this->project->id, 'name' => 'Backend', 'color' => 'blue']);
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);
    $document->categories()->attach([$design->id, $backend->id]);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateCategories', [$this->project, $document]), [
            'category_ids' => [$design->id],
        ])
        ->assertRedirect();

    expect($document->fresh()->categories()->pluck('categories.id')->all())->toBe([$design->id]);
});

it('clears all tags when synced with an empty list', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);
    $document->categories()->attach($design->id);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateCategories', [$this->project, $document]), [
            'category_ids' => [],
        ])
        ->assertRedirect();

    expect($document->fresh()->categories()->count())->toBe(0);
});

it('rejects assigning a document a tag from an unrelated project', function () {
    $otherProject = Project::create(['name' => 'Unrelated Project', 'client_id' => $this->client->id]);
    $category = Category::create(['project_id' => $otherProject->id, 'name' => 'Design', 'color' => 'pink']);
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateCategories', [$this->project, $document]), [
            'category_ids' => [$category->id],
        ])
        ->assertSessionHasErrors('category_ids.0');

    expect($document->fresh()->categories()->count())->toBe(0);
});

it('lets a subproject document use a tag owned by its family root', function () {
    $category = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $document = Document::create([
        'project_id' => $this->child->id,
        'name' => 'A task',
        'type' => 'task',
        'content' => 'content',
    ]);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateCategories', [$this->child, $document]), [
            'category_ids' => [$category->id],
        ])
        ->assertRedirect();

    expect($document->fresh()->categories()->pluck('categories.id')->all())->toBe([$category->id]);
});
