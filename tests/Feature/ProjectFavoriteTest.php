<?php

use App\Models\Client;
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
});

it('lets an org member favorite a project', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'team-member']);

    $this->actingAs($user)
        ->post(route('projects.favorite.store', $this->project))
        ->assertRedirect();

    expect($this->project->favoritedBy()->where('users.id', $user->id)->exists())->toBeTrue();
});

it('lets an org member unfavorite a project', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'team-member']);
    $this->project->favoritedBy()->attach($user->id);

    $this->actingAs($user)
        ->delete(route('projects.favorite.destroy', $this->project))
        ->assertRedirect();

    expect($this->project->favoritedBy()->where('users.id', $user->id)->exists())->toBeFalse();
});

it('does not duplicate the favorite when favorited twice', function () {
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'team-member']);

    $this->actingAs($user)->post(route('projects.favorite.store', $this->project));
    $this->actingAs($user)->post(route('projects.favorite.store', $this->project));

    expect($this->project->favoritedBy()->where('users.id', $user->id)->count())->toBe(1);
});

it('denies favoriting a project the user has no access to', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('projects.favorite.store', $this->project))
        ->assertNotFound();
});

it('favorites the project even when a different org is currently active', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $user = User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'team-member']);
    $otherOrg->users()->attach($user->id, ['role' => 'org-admin']);

    $this->actingAs($user)
        ->withSession(['active_org_id' => $otherOrg->id])
        ->post(route('projects.favorite.store', $this->project))
        ->assertRedirect();

    expect($this->project->favoritedBy()->where('users.id', $user->id)->exists())->toBeTrue();
});
