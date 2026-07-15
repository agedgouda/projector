<?php

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');
});

it('creates a free org via the setup flow', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('organization.setup.store'), [
            'name' => 'Free Org',
            'membership_tier' => 'free',
        ])
        ->assertRedirect();

    $org = Organization::where('name', 'Free Org')->firstOrFail();
    expect($org->membership_tier)->toBe('free');
});

it('creates a pro org via the setup flow', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('organization.setup.store'), [
            'name' => 'Pro Org',
            'membership_tier' => 'pro',
        ])
        ->assertRedirect();

    $org = Organization::where('name', 'Pro Org')->firstOrFail();
    expect($org->membership_tier)->toBe('pro');
});

it('rejects an invalid tier on setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('organization.setup.store'), [
            'name' => 'Bad Org',
            'membership_tier' => 'enterprise',
        ])
        ->assertSessionHasErrors('membership_tier');
});

it('returns correct limits for free tier', function () {
    $org = Organization::factory()->create(['membership_tier' => 'free']);

    $limits = $org->tierLimits();

    expect($limits['users'])->toBe(1)
        ->and($limits['clients'])->toBe(1)
        ->and($limits['projects'])->toBe(1)
        ->and($limits['ai_docs_per_month'])->toBe(10);
});

it('returns null limits for friends_family tier', function () {
    $org = Organization::factory()->create(['membership_tier' => 'friends_family']);

    $limits = $org->tierLimits();

    expect($limits['users'])->toBeNull()
        ->and($limits['clients'])->toBeNull()
        ->and($limits['ai_docs_per_month'])->toBeNull();
});

it('super admin can update an org tier', function () {
    $org = Organization::factory()->create(['membership_tier' => 'free']);

    $this->actingAs($this->superAdmin)
        ->patch(route('admin.organizations.update-tier', $org), ['membership_tier' => 'pro'])
        ->assertRedirect();

    expect($org->fresh()->membership_tier)->toBe('pro');
});

it('non-super-admin cannot update an org tier', function () {
    $org = Organization::factory()->create(['membership_tier' => 'free']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('admin.organizations.update-tier', $org), ['membership_tier' => 'pro'])
        ->assertStatus(404);
});
