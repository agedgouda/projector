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
    $this->org->users()->attach($this->target->id, ['role' => 'team-member']);
});

it('lets a super-admin start impersonating a user', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('users.impersonate', $this->target))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->target);
    expect(session('impersonator_id'))->toBe($this->superAdmin->id);
});

it('forbids a non-super-admin from starting impersonation', function () {
    $regularUser = User::factory()->create();

    $this->actingAs($regularUser)
        ->post(route('users.impersonate', $this->target))
        ->assertNotFound();

    $this->assertAuthenticatedAs($regularUser);
});

it('allows impersonating another super-admin', function () {
    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('super-admin');

    $this->actingAs($this->superAdmin)
        ->post(route('users.impersonate', $otherAdmin))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($otherAdmin);
});

it('refuses to let a super-admin impersonate themselves', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('users.impersonate', $this->superAdmin))
        ->assertStatus(422);

    $this->assertAuthenticatedAs($this->superAdmin);
});

it('refuses to start a second impersonation while already impersonating', function () {
    // The impersonated user must also be a super-admin here, since the impersonate route
    // itself requires the super-admin role — impersonating a regular user would already be
    // blocked from reaching this route at all (covered by the "forbids a non-super-admin"
    // test above), never mind the nested-impersonation guard this test targets.
    $otherAdmin = User::factory()->create();
    $otherAdmin->assignRole('super-admin');
    $anotherTarget = User::factory()->create();

    $this->actingAs($this->superAdmin)
        ->post(route('users.impersonate', $otherAdmin));

    $this->post(route('users.impersonate', $anotherTarget))
        ->assertStatus(409);

    $this->assertAuthenticatedAs($otherAdmin);
    expect(session('impersonator_id'))->toBe($this->superAdmin->id);
});

it('stops impersonation and restores the original admin', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('users.impersonate', $this->target));

    $this->delete(route('impersonate.destroy'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($this->superAdmin);
    expect(session('impersonator_id'))->toBeNull();
});

it('is a no-op to stop impersonation when not currently impersonating', function () {
    $this->actingAs($this->superAdmin)
        ->delete(route('impersonate.destroy'))
        ->assertNotFound();

    $this->assertAuthenticatedAs($this->superAdmin);
});

it('shares the impersonating admin on the page props while impersonating', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('users.impersonate', $this->target));

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $this->target->id)
            ->where('auth.impersonating.id', $this->superAdmin->id)
            ->where('auth.impersonating.name', $this->superAdmin->name)
        );
});

it('does not share an impersonating admin when not impersonating', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.impersonating', null)
        );
});
