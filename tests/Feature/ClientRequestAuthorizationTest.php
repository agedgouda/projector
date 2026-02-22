<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);

    $orgAdminRole = Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    setPermissionsTeamId($this->org->id);
    $this->admin->organizations()->syncWithoutDetaching([$this->org->id]);
    $this->admin->assignRole($orgAdminRole);

    // Member attached to a client (needed to pass client.access middleware)
    $this->existingClient = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Existing Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->member = User::factory()->create();
    $this->member->organizations()->syncWithoutDetaching([$this->org->id]);
    $this->existingClient->users()->attach($this->member->id);
});

it('allows an org-admin to create a client', function () {
    $this->actingAs($this->admin)
        ->post(route('clients.store'), [
            'company_name' => 'New Client Co',
            'contact_name' => 'Alice Smith',
            'contact_phone' => '555-9999',
        ])
        ->assertRedirect();

    expect(Client::where('company_name', 'New Client Co')->exists())->toBeTrue();
});

it('blocks a non-admin member from creating a client', function () {
    $this->actingAs($this->member)
        ->post(route('clients.store'), [
            'company_name' => 'Sneaky Client',
            'contact_name' => 'Hacker',
            'contact_phone' => '555-0000',
        ])
        ->assertNotFound();

    expect(Client::where('company_name', 'Sneaky Client')->exists())->toBeFalse();
});

it('allows an org-admin to update a client', function () {
    $this->actingAs($this->admin)
        ->put(route('clients.update', $this->existingClient), [
            'company_name' => 'Updated Name',
            'contact_name' => 'John Doe',
            'contact_phone' => '555-1234',
        ])
        ->assertRedirect();

    expect($this->existingClient->fresh()->company_name)->toBe('Updated Name');
});

it('blocks a non-admin member from updating a client', function () {
    $this->actingAs($this->member)
        ->put(route('clients.update', $this->existingClient), [
            'company_name' => 'Hacked Name',
            'contact_name' => 'John Doe',
            'contact_phone' => '555-1234',
        ])
        ->assertNotFound();

    expect($this->existingClient->fresh()->company_name)->toBe('Existing Client');
});
