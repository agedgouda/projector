<?php

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->target = User::factory()->create();
    $this->org->users()->attach($this->target->id, ['role' => 'org-admin']);
});

it('promotes a user to super-admin without removing their organization memberships', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('users.promote', $this->target))
        ->assertRedirect();

    $this->target->refresh();
    expect($this->target->hasRole('super-admin'))->toBeTrue();
    expect($this->org->users()->where('user_id', $this->target->id)->exists())->toBeTrue();
    expect($this->org->users()->where('user_id', $this->target->id)->first()->pivot->role)->toBe('org-admin');
});

it('preserves membership in more than one organization when promoting', function () {
    $secondOrg = Organization::create(['name' => 'Beta LLC']);
    $secondOrg->users()->attach($this->target->id, ['role' => 'team-member']);

    $this->actingAs($this->superAdmin)
        ->post(route('users.promote', $this->target))
        ->assertRedirect();

    expect($this->org->users()->where('user_id', $this->target->id)->exists())->toBeTrue();
    expect($secondOrg->users()->where('user_id', $this->target->id)->exists())->toBeTrue();
});

it('refuses to re-promote a user who is already a super-admin', function () {
    $this->target->assignRole('super-admin');

    $this->actingAs($this->superAdmin)
        ->post(route('users.promote', $this->target))
        ->assertRedirect();

    expect(session('error'))->not->toBeNull();
});

it('forbids a non-super-admin from promoting a user', function () {
    $regularUser = User::factory()->create();

    $this->actingAs($regularUser)
        ->post(route('users.promote', $this->target))
        ->assertNotFound();

    expect($this->target->fresh()->hasRole('super-admin'))->toBeFalse();
});
