<?php

use App\Models\Client;
use App\Models\Document;
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

    // Parent + two children — a full 2-level family. ProjectObserver seeds each with the
    // same 4 default kanban columns automatically, so they match unless a test deliberately
    // diverges one.
    $this->parent = Project::create(['name' => 'Parent Project', 'client_id' => $this->client->id]);
    $this->childA = Project::create(['name' => 'Child A', 'client_id' => $this->client->id, 'parent_id' => $this->parent->id]);
    $this->childB = Project::create(['name' => 'Child B', 'client_id' => $this->client->id, 'parent_id' => $this->parent->id]);

    $this->unrelated = Project::create(['name' => 'Unrelated Project', 'client_id' => $this->client->id]);

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'action_items',
        'label' => 'Action Items',
        'is_task' => true,
        'order' => 1,
    ]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->task = Document::create([
        'project_id' => $this->childA->id,
        'name' => 'A Task',
        'type' => 'action_items',
        'content' => 'x',
    ]);
});

// ── Family resolution ───────────────────────────────────────────────────────────

it('resolves a top-level project\'s family as itself plus its direct children', function () {
    $ids = $this->parent->familyProjectIds();

    expect($ids)->toEqualCanonicalizing([
        (string) $this->parent->id,
        (string) $this->childA->id,
        (string) $this->childB->id,
    ]);
});

it('resolves a subproject\'s family by walking up to its parent first', function () {
    $ids = $this->childA->familyProjectIds();

    expect($ids)->toEqualCanonicalizing([
        (string) $this->parent->id,
        (string) $this->childA->id,
        (string) $this->childB->id,
    ]);
});

// ── Move ─────────────────────────────────────────────────────────────────────────

it('moves a task to a sibling project within the same family', function () {
    $this->actingAs($this->admin)
        ->patch(route('projects.documents.move', [$this->childA, $this->task]), [
            'project_id' => $this->childB->id,
        ])
        ->assertRedirect();

    expect($this->task->fresh()->project_id)->toBe($this->childB->id);
});

it('redirects to the task\'s own new url after a move, not back to the old (now-invalid) one', function () {
    // The page the request came from is keyed to the *old* project in its URL
    // (/projects/{old}/documents/{doc}), which 404s the instant project_id changes — a
    // plain back() would leave the user on a URL that's already invalid. Caught via a real
    // browser click-through, not a unit test, before this fix existed.
    $this->actingAs($this->admin)
        ->patch(route('projects.documents.move', [$this->childA, $this->task]), [
            'project_id' => $this->childB->id,
        ])
        ->assertRedirect(route('projects.documents.show', [$this->childB, $this->task]));
});

it('moves a task from a subproject up to its parent', function () {
    $this->actingAs($this->admin)
        ->patch(route('projects.documents.move', [$this->childA, $this->task]), [
            'project_id' => $this->parent->id,
        ])
        ->assertRedirect();

    expect($this->task->fresh()->project_id)->toBe($this->parent->id);
});

it('refuses to move a task to a project outside its family', function () {
    $this->actingAs($this->admin)
        ->patch(route('projects.documents.move', [$this->childA, $this->task]), [
            'project_id' => $this->unrelated->id,
        ])
        ->assertSessionHasErrors('project_id');

    expect($this->task->fresh()->project_id)->toBe($this->childA->id);
});

it('refuses to move a task to a board whose columns don\'t match', function () {
    $this->childB->kanbanColumns()->create(['key' => 'blocked', 'label' => 'Blocked', 'color' => 'red', 'order' => 5]);

    $this->actingAs($this->admin)
        ->patch(route('projects.documents.move', [$this->childA, $this->task]), [
            'project_id' => $this->childB->id,
        ])
        ->assertSessionHasErrors('project_id');

    expect($this->task->fresh()->project_id)->toBe($this->childA->id);
});

it('refuses to move a task to the board it is already on', function () {
    $this->actingAs($this->admin)
        ->patch(route('projects.documents.move', [$this->childA, $this->task]), [
            'project_id' => $this->childA->id,
        ])
        ->assertSessionHasErrors('project_id');
});

it('drops a stale board link that pointed to the new home project', function () {
    $this->task->linkedProjects()->attach($this->childB->id);

    $this->actingAs($this->admin)
        ->patch(route('projects.documents.move', [$this->childA, $this->task]), [
            'project_id' => $this->childB->id,
        ])
        ->assertRedirect();

    expect($this->task->fresh()->linkedProjects()->pluck('projects.id')->all())->toBe([]);
});

it('forbids a user unrelated to the project from moving a task', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->patch(route('projects.documents.move', [$this->childA, $this->task]), [
            'project_id' => $this->childB->id,
        ])
        ->assertNotFound();
});

// ── Show on multiple boards ───────────────────────────────────────────────────────

it('links a task to an additional board within its family', function () {
    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateBoards', [$this->childA, $this->task]), [
            'project_ids' => [$this->childB->id],
        ])
        ->assertRedirect();

    expect($this->task->fresh()->linkedProjects()->pluck('projects.id')->all())->toBe([$this->childB->id]);
});

it('syncs the board link set, removing ones no longer present', function () {
    $this->task->linkedProjects()->attach([$this->parent->id, $this->childB->id]);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateBoards', [$this->childA, $this->task]), [
            'project_ids' => [$this->childB->id],
        ])
        ->assertRedirect();

    expect($this->task->fresh()->linkedProjects()->pluck('projects.id')->all())->toBe([$this->childB->id]);
});

it('clears all board links when given an empty list', function () {
    $this->task->linkedProjects()->attach($this->childB->id);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateBoards', [$this->childA, $this->task]), [
            'project_ids' => [],
        ])
        ->assertRedirect();

    expect($this->task->fresh()->linkedProjects()->pluck('projects.id')->all())->toBe([]);
});

it('refuses to link a task to its own home board', function () {
    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateBoards', [$this->childA, $this->task]), [
            'project_ids' => [$this->childA->id],
        ])
        ->assertSessionHasErrors('project_ids');
});

it('refuses to link a task to a project outside its family', function () {
    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateBoards', [$this->childA, $this->task]), [
            'project_ids' => [$this->unrelated->id],
        ])
        ->assertSessionHasErrors('project_ids');
});

it('refuses to link a task to a board whose columns don\'t match', function () {
    $this->childB->kanbanColumns()->create(['key' => 'blocked', 'label' => 'Blocked', 'color' => 'red', 'order' => 5]);

    $this->actingAs($this->admin)
        ->put(route('projects.documents.updateBoards', [$this->childA, $this->task]), [
            'project_ids' => [$this->childB->id],
        ])
        ->assertSessionHasErrors('project_ids');

    expect($this->task->fresh()->linkedProjects()->pluck('projects.id')->all())->toBe([]);
});

it('forbids a user unrelated to the project from managing board links', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->put(route('projects.documents.updateBoards', [$this->childA, $this->task]), [
            'project_ids' => [$this->childB->id],
        ])
        ->assertNotFound();
});

// ── Kanban board rendering ─────────────────────────────────────────────────────────

it('shows a linked task on both its home board and the board it is also shown on', function () {
    $this->task->linkedProjects()->attach($this->childB->id);

    $home = $this->childA->fresh()->load(['documents', 'linkedDocuments.project']);
    $other = $this->childB->fresh()->load(['documents', 'linkedDocuments.project']);

    $homeDocs = $home->getKanbanDocuments();
    $otherDocs = $other->getKanbanDocuments();

    expect($homeDocs->firstWhere('id', $this->task->id)['is_linked'])->toBeFalse()
        ->and($otherDocs->firstWhere('id', $this->task->id)['is_linked'])->toBeTrue()
        ->and($otherDocs->firstWhere('id', $this->task->id)['home_project_id'])->toBe($this->childA->id)
        ->and($otherDocs->firstWhere('id', $this->task->id)['home_project_name'])->toBe('Child A');
});

it('does not show a task on a board it is not linked to', function () {
    $unrelatedDocs = $this->unrelated->fresh()->load(['documents', 'linkedDocuments'])->getKanbanDocuments();

    expect($unrelatedDocs->firstWhere('id', $this->task->id))->toBeNull();
});
