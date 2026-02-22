<?php

use App\Models\Client;
use App\Models\Comment;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Task;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $projectType = ProjectType::create(['name' => 'General', 'document_schema' => []]);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
        'project_type_id' => $projectType->id,
    ]);
    $this->task = Task::create([
        'project_id' => $this->project->id,
        'title' => 'Test Task',
        'status' => 'todo',
        'priority' => 'low',
    ]);
    $this->document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Test Doc',
        'type' => 'note',
        'content' => 'Hello world',
    ]);

    $orgAdminRole = Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    setPermissionsTeamId($this->org->id);
    $this->admin->organizations()->syncWithoutDetaching([$this->org->id]);
    $this->admin->assignRole($orgAdminRole);
    $this->client->users()->attach($this->admin->id);

    $this->member = User::factory()->create();
    $this->member->organizations()->syncWithoutDetaching([$this->org->id]);
    $this->client->users()->attach($this->member->id);
});

it('allows a member to comment on a task they have access to', function () {
    $this->actingAs($this->member)
        ->post(route('comments.store'), [
            'body' => 'Great task!',
            'type' => 'task',
            'id' => $this->task->id,
        ])
        ->assertRedirect();

    expect(Comment::count())->toBe(1);
});

it('allows a member to comment on a document they have access to', function () {
    $this->actingAs($this->member)
        ->post(route('comments.store'), [
            'body' => 'Great doc!',
            'type' => 'document',
            'id' => $this->document->id,
        ])
        ->assertRedirect();

    expect(Comment::count())->toBe(1);
});

it('blocks a user from another org from commenting on a task', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $stranger = User::factory()->create();
    setPermissionsTeamId($otherOrg->id);
    $stranger->organizations()->syncWithoutDetaching([$otherOrg->id]);

    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'Bob',
        'contact_phone' => '555-0000',
    ]);
    $otherClient->users()->attach($stranger->id);

    $this->actingAs($stranger)
        ->post(route('comments.store'), [
            'body' => 'Cross-org comment!',
            'type' => 'task',
            'id' => $this->task->id,
        ])
        ->assertNotFound();

    expect(Comment::count())->toBe(0);
});

it('blocks a user from another org from commenting on a document', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $stranger = User::factory()->create();
    setPermissionsTeamId($otherOrg->id);
    $stranger->organizations()->syncWithoutDetaching([$otherOrg->id]);

    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'Bob',
        'contact_phone' => '555-0000',
    ]);
    $otherClient->users()->attach($stranger->id);

    $this->actingAs($stranger)
        ->post(route('comments.store'), [
            'body' => 'Cross-org comment!',
            'type' => 'document',
            'id' => $this->document->id,
        ])
        ->assertNotFound();

    expect(Comment::count())->toBe(0);
});

it('allows the comment author to delete their own comment', function () {
    $comment = Comment::create([
        'body' => 'My comment',
        'user_id' => $this->member->id,
        'commentable_type' => Task::class,
        'commentable_id' => $this->task->id,
    ]);

    $this->actingAs($this->member)
        ->delete(route('comments.destroy', $comment))
        ->assertRedirect();

    expect(Comment::count())->toBe(0);
});

it('allows an org-admin to delete any comment in their org', function () {
    $comment = Comment::create([
        'body' => 'Member comment',
        'user_id' => $this->member->id,
        'commentable_type' => Task::class,
        'commentable_id' => $this->task->id,
    ]);

    $this->actingAs($this->admin)
        ->delete(route('comments.destroy', $comment))
        ->assertRedirect();

    expect(Comment::count())->toBe(0);
});

it('blocks a non-author non-admin from deleting a comment', function () {
    $author = User::factory()->create();
    $author->organizations()->syncWithoutDetaching([$this->org->id]);
    $this->client->users()->attach($author->id);

    $comment = Comment::create([
        'body' => 'Author comment',
        'user_id' => $author->id,
        'commentable_type' => Task::class,
        'commentable_id' => $this->task->id,
    ]);

    $this->actingAs($this->member)
        ->delete(route('comments.destroy', $comment))
        ->assertNotFound();

    expect(Comment::count())->toBe(1);
});
