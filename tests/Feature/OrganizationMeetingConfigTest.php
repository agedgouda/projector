<?php

use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    setPermissionsTeamId($this->org->id);
});

it('stores zoom meeting config', function () {
    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => $this->org->name,
            'meeting_provider' => 'zoom',
            'meeting_config' => [
                'account_id' => 'acct_123',
                'client_id' => 'client_abc',
                'client_secret' => 'secret_xyz',
            ],
        ])
        ->assertRedirect();

    $this->org->refresh();

    expect($this->org->meeting_provider)->toBe('zoom')
        ->and($this->org->meeting_config['account_id'])->toBe('acct_123')
        ->and($this->org->meeting_config['client_id'])->toBe('client_abc')
        ->and($this->org->meeting_config['client_secret'])->toBe('secret_xyz');
});

it('stores teams meeting config', function () {
    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => $this->org->name,
            'meeting_provider' => 'teams',
            'meeting_config' => [
                'tenant_id' => 'tenant_abc',
                'client_id' => 'client_def',
                'client_secret' => 'secret_ghi',
            ],
        ])
        ->assertRedirect();

    $this->org->refresh();

    expect($this->org->meeting_provider)->toBe('teams')
        ->and($this->org->meeting_config['tenant_id'])->toBe('tenant_abc')
        ->and($this->org->meeting_config['client_id'])->toBe('client_def');
});

it('stores google meet config', function () {
    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => $this->org->name,
            'meeting_provider' => 'google_meet',
            'meeting_config' => [
                'client_id' => 'google_client',
                'client_secret' => 'google_secret',
            ],
        ])
        ->assertRedirect();

    $this->org->refresh();

    expect($this->org->meeting_provider)->toBe('google_meet')
        ->and($this->org->meeting_config['client_id'])->toBe('google_client');
});

it('clears meeting config when provider is removed', function () {
    $this->org->meeting_provider = 'zoom';
    $this->org->meeting_config = ['account_id' => 'acct_123', 'client_id' => 'c', 'client_secret' => 's'];
    $this->org->save();

    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => $this->org->name,
            'meeting_provider' => '',
        ])
        ->assertRedirect();

    $this->org->refresh();

    expect($this->org->meeting_provider)->toBeNull()
        ->and($this->org->meeting_config)->toBeNull();
});

it('preserves client secret when blank is submitted', function () {
    $this->org->meeting_provider = 'zoom';
    $this->org->meeting_config = ['account_id' => 'acct_123', 'client_id' => 'c', 'client_secret' => 'existing_secret'];
    $this->org->save();

    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => $this->org->name,
            'meeting_provider' => 'zoom',
            'meeting_config' => [
                'account_id' => 'acct_123',
                'client_id' => 'c',
                'client_secret' => '',
            ],
        ])
        ->assertRedirect();

    $this->org->refresh();

    expect($this->org->meeting_config['client_secret'])->toBe('existing_secret');
});

it('rejects invalid meeting provider', function () {
    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), [
            'name' => $this->org->name,
            'meeting_provider' => 'webex',
        ])
        ->assertSessionHasErrors('meeting_provider');
});

it('exposes meeting config form data on edit page', function () {
    $this->org->meeting_provider = 'zoom';
    $this->org->meeting_config = ['account_id' => 'acct_123', 'client_id' => 'cid', 'client_secret' => 'secret'];
    $this->org->save();

    $this->actingAs($this->admin)
        ->get(route('organizations.edit', $this->org))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Organizations/Edit')
            ->where('organization.meeting_provider', 'zoom')
            ->where('organization.meeting_config_form.account_id', 'acct_123')
            ->where('organization.meeting_config_form.client_id', 'cid')
            ->where('organization.meeting_config_form.has_client_secret', true)
        );
});
