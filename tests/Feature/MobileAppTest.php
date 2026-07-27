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

it('shows a note and its resulting action items on mobile', function () {
    $note = $this->project->documents()->create([
        'type' => 'action_items',
        'name' => 'Kickoff Notes',
        'content' => 'Full transcript.',
        'processed_at' => now(),
    ]);
    $child = Document::create([
        'project_id' => $this->project->id,
        'parent_id' => $note->id,
        'type' => 'task',
        'name' => 'Follow up',
        'content' => 'Send the proposal.',
    ]);

    $this->actingAs($this->user)
        ->withSession(['active_org_id' => $this->org->id])
        ->get(route('mobile.documents.show', [$this->project, $note]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Mobile/Documents/Show')
            ->where('document.content', 'Full transcript.')
            ->has('children', 1)
            ->where('children.0.id', $child->id)
        );
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
