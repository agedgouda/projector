<?php

use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->org->update([
        'meeting_provider' => 'zoom',
        'meeting_config' => ['account_id' => 'acct-123', 'client_id' => 'client-abc', 'client_secret' => 'shh'],
    ]);

    AiUsageLog::create([
        'organization_id' => $this->org->id,
        'driver' => 'openai',
        'model' => 'gpt',
        'type' => 'llm',
        'input_tokens' => 10,
        'output_tokens' => 10,
        'cost_usd' => 1.25,
    ]);
});

function organizationPageProps(User $user, Organization $org): array
{
    return test()->actingAs($user)
        ->get(route('organizations.index', ['org' => $org->id]))
        ->assertOk()
        ->viewData('page')['props'];
}

it('gives non-admin members no configuration or AI usage data', function (string $role) {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => $role]);

    $props = organizationPageProps($member, $this->org);

    expect($props['currentOrg']['can']['update'])->toBeFalse()
        ->and($props['currentOrg']['llm_config_form'])->toBeNull()
        ->and($props['currentOrg']['vector_config_form'])->toBeNull()
        ->and($props['currentOrg']['meeting_config_form'])->toBeNull()
        ->and($props['usageTotals']['documents_processed'])->toBe(0)
        ->and($props['usageTotals']['cost_usd'])->toEqual(0)
        ->and($props['usageByClient'])->toBeEmpty();
})->with(['team-member', 'project-lead']);

it('gives org-admins their configuration and AI usage data', function () {
    $admin = User::factory()->create();
    $this->org->users()->attach($admin->id, ['role' => 'org-admin']);

    $props = organizationPageProps($admin, $this->org);

    expect($props['currentOrg']['can']['update'])->toBeTrue()
        ->and($props['currentOrg']['meeting_config_form']['client_id'])->toBe('client-abc')
        ->and($props['currentOrg']['meeting_config_form']['has_client_secret'])->toBeTrue()
        ->and($props['usageTotals']['documents_processed'])->toBe(1)
        ->and($props['usageTotals']['cost_usd'])->toEqual(1.25);
});

it('gives super-admins configuration and AI usage data even without a membership role', function () {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('super-admin');
    $this->org->users()->attach($superAdmin->id, ['role' => 'team-member']);

    $props = organizationPageProps($superAdmin, $this->org);

    expect($props['currentOrg']['can']['update'])->toBeTrue()
        ->and($props['currentOrg']['meeting_config_form'])->not()->toBeNull()
        ->and($props['usageTotals']['documents_processed'])->toBe(1);
});
