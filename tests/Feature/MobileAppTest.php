<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->user = User::factory()->withoutTwoFactor()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $this->projectType = ProjectType::factory()->create();

    $this->project = Project::create([
        'name' => 'Mobile Redesign',
        'client_id' => $this->client->id,
        'project_type_id' => $this->projectType->id,
    ]);
});

it('shows the mobile login page', function () {
    $this->get(route('mobile.login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Mobile/Login'));
});

it('redirects an unauthenticated mobile request to the mobile login page, not the desktop one', function () {
    $this->get(route('mobile.dashboard'))
        ->assertRedirect(route('mobile.login'));
});

it('redirects an unauthenticated desktop request to the desktop login page', function () {
    $this->get(route('projects.index'))
        ->assertRedirect(route('login', ['expired' => 1]));
});

it('lists visible projects on the mobile dashboard', function () {
    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Dashboard')
            ->has('projects', 1)
            ->where('projects.0.name', 'Mobile Redesign')
        );
});

it('shows a project\'s notes on the mobile project page', function () {
    $note = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Kickoff Notes',
        'content' => 'Some content',
        'processed_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Projects/Show')
            ->has('notes', 1)
            ->where('notes.0.id', $note->id)
            ->where('notes.0.status', 'processed')
        );
});

it('shows root-level documents of any type on the mobile project page, not just intake notes', function () {
    $intakeNote = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Kickoff Notes',
        'content' => 'Some content',
        'processed_at' => now(),
    ]);
    $requirementsDoc = $this->project->documents()->create([
        'type' => 'requirements',
        'name' => 'Requirements Doc',
        'content' => 'Some requirements',
        'processed_at' => now(),
    ]);
    $taskDoc = $this->project->documents()->create([
        'type' => 'task',
        'name' => 'Brand Guide',
        'content' => 'Some task',
        'processed_at' => now(),
    ]);
    // A nested document should not appear in the top-level list.
    Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $intakeNote->id,
        'type' => 'action_items',
        'name' => 'Action Items',
        'content' => 'Nested content',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.projects.show', $this->project))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page->component('Mobile/Projects/Show')->has('notes', 3));

    $noteIds = collect($response->original->getData()['page']['props']['notes'])->pluck('id')->all();
    expect($noteIds)->toEqualCanonicalizing([$intakeNote->id, $requirementsDoc->id, $taskDoc->id]);
});

it('blocks viewing a project from another organization on mobile', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $otherProject = Project::create([
        'name' => 'Other Project',
        'client_id' => $otherClient->id,
        'project_type_id' => $this->projectType->id,
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.projects.show', $otherProject))
        ->assertNotFound();
});

it('shows a document\'s own content on mobile, with no nested children list of its own', function () {
    $note = $this->project->documents()->create([
        'type' => 'action_items',
        'name' => 'Kickoff Notes',
        'content' => 'Full transcript.',
        'processed_at' => now(),
    ]);
    Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $note->id,
        'type' => 'task',
        'name' => 'Follow up',
        'content' => 'Send the proposal.',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.documents.show', [$this->project, $note]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Documents/Show')
            ->where('document.content', 'Full transcript.')
        );

    // Browsing to related items now happens via the note's own index page instead.
    expect($response->original->getData()['page']['props'])->not->toHaveKey('children');
});

it('resolves the top-level note id for a document\'s back link, even when deeply nested', function () {
    $note = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Kickoff Notes',
        'content' => 'Full transcript.',
        'processed_at' => now(),
    ]);
    $actionItems = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $note->id,
        'type' => 'action_items',
        'name' => 'Action Items',
        'content' => '',
    ]);
    $task = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $actionItems->id,
        'type' => 'task',
        'name' => 'Follow up',
        'content' => '',
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.documents.show', [$this->project, $task]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Documents/Show')
            ->where('noteId', $note->id)
        );
});

it('shows the same task details on mobile that the desktop detail sheet shows', function () {
    \App\Models\DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);
    \App\Models\DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'action_items',
        'label' => 'Action Items',
        'is_task' => false,
        'order' => 2,
    ]);

    $note = $this->project->documents()->create([
        'type' => 'action_items',
        'name' => 'Kickoff Notes',
        'content' => 'Full transcript.',
        'processed_at' => now(),
    ]);
    // forceFill (not create/mass-assign) because 'status' isn't fillable, but must still be
    // set before the DocumentObserver's creating() hook runs, or it'll override task_status.
    $child = new Document;
    $child->forceFill([
        'project_id' => $this->project->id,
        'parent_id' => $note->id,
        'type' => 'task',
        'name' => 'Follow up',
        'content' => 'Send the proposal.',
        'priority' => 'high',
        'status' => 'in_progress',
        'task_status' => 'in_progress',
        'due_at' => '2026-08-01',
        'assignee_id' => $this->user->id,
    ]);
    $child->save();

    $response = $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.documents.show', [$this->project, $child]))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('Mobile/Documents/Show')
        ->where('document.typeLabel', 'Task')
        ->where('document.isTask', true)
        ->where('document.priority', 'high')
        ->where('document.taskStatus', 'in_progress')
        ->where('document.assignee.id', $this->user->id)
        ->where('document.assignee.name', $this->user->name)
    );

    $dueAt = $response->original->getData()['page']['props']['document']['dueAt'];
    expect($dueAt)->toStartWith('2026-08-01');
});

it('shows a note\'s own index of itself plus every nested descendant, in depth order', function () {
    $note = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Kickoff Notes',
        'content' => 'Full transcript.',
        'processed_at' => now(),
    ]);
    $actionItems = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $note->id,
        'type' => 'action_items',
        'name' => 'Action Items',
        'content' => '',
    ]);
    $task = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $actionItems->id,
        'type' => 'task',
        'name' => 'Follow up',
        'content' => '',
    ]);

    $response = $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.notes.show', [$this->project, $note]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Notes/Show')
            ->where('note.id', $note->id)
            ->has('items', 3)
        );

    $items = collect($response->original->getData()['page']['props']['items']);
    expect($items->pluck('id')->all())->toBe([$note->id, $actionItems->id, $task->id]);
    expect($items->pluck('depth')->all())->toBe([0, 1, 2]);
});

it('returns 404 for a note index requested on a document that is not itself a root note', function () {
    $note = $this->project->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Kickoff Notes',
        'content' => 'Full transcript.',
    ]);
    $child = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $note->id,
        'type' => 'action_items',
        'name' => 'Action Items',
        'content' => '',
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.notes.show', [$this->project, $child]))
        ->assertNotFound();
});

it('returns 404 when the note does not belong to the given project on mobile', function () {
    $otherProject = Project::create([
        'name' => 'Other Project In Same Org',
        'client_id' => $this->client->id,
        'project_type_id' => $this->projectType->id,
    ]);
    $note = $otherProject->documents()->create([
        'type' => config('workflow.intake_key'),
        'name' => 'Notes',
        'content' => '',
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.documents.show', [$this->project, $note]))
        ->assertNotFound();
});
