<?php

use App\Models\BugReport;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->user = User::factory()->create();

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');
});

it('renders the bug report create page for authenticated users', function () {
    $this->actingAs($this->user)
        ->get(route('bug-reports.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('BugReports/Create'));
});

it('redirects unauthenticated users from the create page', function () {
    $this->get(route('bug-reports.create'))
        ->assertRedirect();
});

it('stores a bug report for any authenticated user', function () {
    $this->actingAs($this->user)
        ->post(route('bug-reports.store'), [
            'title' => 'Button does not work',
            'description' => 'When I click Save nothing happens.',
            'page_url' => 'https://app.test/projects',
        ])
        ->assertRedirect();

    $report = BugReport::first();
    expect($report->title)->toBe('Button does not work')
        ->and($report->description)->toBe('When I click Save nothing happens.')
        ->and($report->page_url)->toBe('https://app.test/projects')
        ->and($report->user_id)->toBe($this->user->id)
        ->and($report->status)->toBe('open');
});

it('validates required fields when storing a bug report', function () {
    $this->actingAs($this->user)
        ->post(route('bug-reports.store'), [])
        ->assertSessionHasErrors(['title', 'description']);
});

it('renders the bug reports index for super admins', function () {
    BugReport::create([
        'user_id' => $this->user->id,
        'title' => 'Test bug',
        'description' => 'Something broke.',
    ]);

    $this->actingAs($this->superAdmin)
        ->get(route('bug-reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('BugReports/Index')
            ->has('bugReports', 1)
        );
});

it('blocks non-super-admins from viewing the bug reports index', function () {
    $this->actingAs($this->user)
        ->get(route('bug-reports.index'))
        ->assertStatus(404);
});

it('toggles a bug report status to resolved', function () {
    $report = BugReport::create([
        'user_id' => $this->user->id,
        'title' => 'Test bug',
        'description' => 'Something broke.',
        'status' => 'open',
    ]);

    $this->actingAs($this->superAdmin)
        ->patch(route('bug-reports.update', $report))
        ->assertRedirect();

    expect($report->fresh()->status)->toBe('resolved');
});

it('toggles a resolved bug report back to open', function () {
    $report = BugReport::create([
        'user_id' => $this->user->id,
        'title' => 'Test bug',
        'description' => 'Something broke.',
        'status' => 'resolved',
    ]);

    $this->actingAs($this->superAdmin)
        ->patch(route('bug-reports.update', $report))
        ->assertRedirect();

    expect($report->fresh()->status)->toBe('open');
});
