<?php

use App\Models\AiTemplate;
use App\Models\Organization;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->orgA = Organization::create(['name' => 'Org A']);
    $this->orgB = Organization::create(['name' => 'Org B']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    $this->orgAAdmin = User::factory()->create();
    $this->orgA->users()->attach($this->orgAAdmin->id, ['role' => 'org-admin']);

    $this->orgBMember = User::factory()->create();
    $this->orgB->users()->attach($this->orgBMember->id, ['role' => 'team-member']);

    $this->globalTemplate = AiTemplate::create([
        'name' => 'Global Template',
        'type' => 'workflow',
        'system_prompt' => 'sys',
        'user_prompt' => 'usr',
    ]);

    $this->orgATemplate = AiTemplate::create([
        'name' => 'Org A Template',
        'type' => 'workflow',
        'organization_id' => $this->orgA->id,
        'system_prompt' => 'sys',
        'user_prompt' => 'usr',
    ]);

    $this->orgBTemplate = AiTemplate::create([
        'name' => 'Org B Template',
        'type' => 'workflow',
        'organization_id' => $this->orgB->id,
        'system_prompt' => 'sys',
        'user_prompt' => 'usr',
    ]);
});

it('super-admin sees all templates', function () {
    setPermissionsTeamId(null);

    $response = $this->actingAs($this->superAdmin)
        ->get(route('ai-templates.index'));

    $response->assertOk();

    $names = collect($response->original->getData()['page']['props']['templates'])->pluck('name');
    expect($names)->toContain('Global Template')
        ->toContain('Org A Template')
        ->toContain('Org B Template');
});

it('org-admin sees global and their org templates only', function () {
    setPermissionsTeamId($this->orgA->id);

    $response = $this->actingAs($this->orgAAdmin)
        ->get(route('ai-templates.index'));

    $response->assertOk();

    $names = collect($response->original->getData()['page']['props']['templates'])->pluck('name');
    expect($names)->toContain('Global Template')
        ->toContain('Org A Template')
        ->not->toContain('Org B Template');
});

it('org-admin can create a template for their org', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'New Org A Template',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertRedirect();

    $created = AiTemplate::where('name', 'New Org A Template')->firstOrFail();
    expect($created->organization_id)->toBe($this->orgA->id);
});

it('super-admin creates a global template', function () {
    setPermissionsTeamId(null);

    $this->actingAs($this->superAdmin)
        ->post(route('ai-templates.store'), [
            'name' => 'New Global Template',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertRedirect();

    $created = AiTemplate::where('name', 'New Global Template')->firstOrFail();
    expect($created->organization_id)->toBeNull();
});

it('org-admin can update their own org template', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->put(route('ai-templates.update', $this->orgATemplate), [
            'name' => 'Updated',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertRedirect();

    expect($this->orgATemplate->fresh()->name)->toBe('Updated');
});

it('org-admin cannot update a global template', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->put(route('ai-templates.update', $this->globalTemplate), [
            'name' => 'Hacked',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertNotFound();
});

it('org-admin cannot update another org template', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->put(route('ai-templates.update', $this->orgBTemplate), [
            'name' => 'Hacked',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertNotFound();
});

it('team member cannot create a template', function () {
    setPermissionsTeamId($this->orgB->id);

    $this->actingAs($this->orgBMember)
        ->post(route('ai-templates.store'), [
            'name' => 'Unauthorized',
            'system_prompt' => 'sys',
            'user_prompt' => 'usr',
        ])
        ->assertNotFound();
});
