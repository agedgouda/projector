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

    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);
});

it('opens the requested tab', function (string $tab) {
    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project).'?tab='.$tab)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activeTab', $tab))
        ->assertCookie('last_active_tab', $tab);
})->with(['tasks', 'reports', 'calendar', 'hierarchy', 'recordings']);

it('falls back to tasks for an unknown tab and does not remember it', function () {
    $this->actingAs($this->user)
        ->get(route('projects.show', $this->project).'?tab=documentation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activeTab', 'tasks'))
        ->assertCookie('last_active_tab', 'tasks');
});

it('reopens the remembered tab when none is requested', function () {
    $this->actingAs($this->user)
        ->withCookie('last_active_tab', 'calendar')
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('activeTab', 'calendar'));
});

it('ignores an unknown remembered tab', function () {
    $this->actingAs($this->user)
        ->withCookie('last_active_tab', 'documentation')
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn ($page) => $page->where('activeTab', 'tasks'));
});

it('uses the remembered tab when the requested one is unknown', function () {
    $this->actingAs($this->user)
        ->withCookie('last_active_tab', 'reports')
        ->get(route('projects.show', $this->project).'?tab=bogus')
        ->assertInertia(fn ($page) => $page->where('activeTab', 'reports'));
});
