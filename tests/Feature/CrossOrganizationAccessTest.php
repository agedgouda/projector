<?php

use App\Models\Client;
use App\Models\Comment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/*
| Org A's admin trying to reach org B's data. Every check here must hold even when the
| attacker is an org-admin, since anyone can become one by creating their own org.
*/

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->orgA = Organization::create(['name' => 'A Org']);
    $this->orgB = Organization::create(['name' => 'B Org']);

    $this->adminA = User::factory()->create();
    $this->orgA->users()->attach($this->adminA->id, ['role' => 'org-admin']);

    $this->clientB = Client::create([
        'organization_id' => $this->orgB->id,
        'company_name' => 'B Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->projectB = Project::create(['name' => 'B Project', 'client_id' => $this->clientB->id]);
    $this->taskB = Task::create([
        'project_id' => $this->projectB->id,
        'title' => 'B Task',
        'status' => 'todo',
        'priority' => 'low',
    ]);

    $this->memberB = User::factory()->create();
    $this->orgB->users()->attach($this->memberB->id, ['role' => 'team-member']);
    $this->clientB->users()->attach($this->memberB->id);
});

function actAsAdminOfOrgA(): mixed
{
    $orgAId = test()->orgA->id;

    return test()->actingAs(test()->adminA)
        ->withCookie('last_org_id', $orgAId)
        ->withSession(['active_org_id' => $orgAId]);
}

it('blocks an admin of another org from deleting a comment', function () {
    $comment = Comment::create([
        'body' => 'B comment',
        'user_id' => $this->memberB->id,
        'commentable_type' => Task::class,
        'commentable_id' => $this->taskB->id,
    ]);

    actAsAdminOfOrgA()->delete(route('comments.destroy', $comment))->assertNotFound();

    expect(Comment::count())->toBe(1);
});

it('blocks an admin of another org from changing or removing a member\'s role there', function (?string $role) {
    actAsAdminOfOrgA()
        ->put(route('users.update', $this->memberB), ['organization_id' => $this->orgB->id, 'role' => $role])
        ->assertNotFound();

    expect($this->memberB->roleInOrganization($this->orgB->id))->toBe('team-member');
})->with(['promote to admin' => 'org-admin', 'remove from org' => null]);

it('still lets an org admin change a role in their own org', function () {
    $memberA = User::factory()->create();
    $this->orgA->users()->attach($memberA->id, ['role' => 'team-member']);

    actAsAdminOfOrgA()
        ->put(route('users.update', $memberA), ['organization_id' => $this->orgA->id, 'role' => 'project-lead'])
        ->assertRedirect();

    expect($memberA->roleInOrganization($this->orgA->id))->toBe('project-lead');
});

it('blocks a user from viewing a client in an org they do not belong to', function () {
    actAsAdminOfOrgA()->get(route('clients.show', $this->clientB))->assertNotFound();
});

it('still lets an org member view a client in their own org', function () {
    $this->actingAs($this->memberB)->get(route('clients.show', $this->clientB))->assertOk();
});

it('ignores a switch to an org the user does not belong to', function () {
    $response = actAsAdminOfOrgA()->get(route('dashboard', ['org' => $this->orgB->id]));

    $response->assertOk();
    expect($response->inertiaProps('auth.active_org_id'))->toBe($this->orgA->id)
        ->and(session('active_org_id'))->toBe($this->orgA->id)
        ->and($response->inertiaProps('currentOrganization.id'))->toBe($this->orgA->id);
});

it('ignores a saved org the user was removed from', function () {
    $this->orgB->users()->detach($this->memberB->id);
    $this->orgA->users()->attach($this->memberB->id, ['role' => 'team-member']);

    $response = $this->actingAs($this->memberB)
        ->withCookie('last_org_id', $this->orgB->id)
        ->withSession(['active_org_id' => $this->orgB->id])
        ->get(route('dashboard'));

    $response->assertOk();
    expect($response->inertiaProps('auth.active_org_id'))->toBe($this->orgA->id)
        ->and($response->inertiaProps('currentOrganization.id'))->toBe($this->orgA->id);
});

it('still lets a member switch to their own other org', function () {
    $this->orgB->users()->attach($this->adminA->id, ['role' => 'org-admin']);

    $response = actAsAdminOfOrgA()->get(route('dashboard', ['org' => $this->orgB->id]));

    expect($response->inertiaProps('auth.active_org_id'))->toBe($this->orgB->id)
        ->and($response->inertiaProps('currentOrganization.id'))->toBe($this->orgB->id);
});
