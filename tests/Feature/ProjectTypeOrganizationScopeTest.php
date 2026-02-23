<?php

use App\Models\Organization;
use App\Models\ProjectType;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

    $this->orgA = Organization::create(['name' => 'Org A']);
    $this->orgB = Organization::create(['name' => 'Org B']);

    // Super admin
    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    // Org A admin
    setPermissionsTeamId($this->orgA->id);
    $this->orgAAdmin = User::factory()->create();
    $this->orgAAdmin->organizations()->syncWithoutDetaching([$this->orgA->id]);
    $this->orgAAdmin->assignRole('org-admin');

    setPermissionsTeamId(null);

    $this->typeA = ProjectType::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Type A']);
    $this->typeB = ProjectType::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Type B']);
});

it('org-admin can create a project type for their org', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('project-types.store'), [
            'name' => 'New Org Type',
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
        ])
        ->assertRedirect();

    $created = ProjectType::where('name', 'New Org Type')->firstOrFail();
    expect($created->organization_id)->toBe($this->orgA->id);
});

it('org-admin only sees their org types in index', function () {
    setPermissionsTeamId($this->orgA->id);

    $response = $this->actingAs($this->orgAAdmin)
        ->get(route('project-types.index'));

    $response->assertOk();

    $types = collect($response->original->getData()['page']['props']['projectTypes']);
    expect($types->pluck('id')->toArray())->toContain($this->typeA->id)
        ->and($types->pluck('id')->toArray())->not->toContain($this->typeB->id);
});

it('org-admin cannot update another orgs project type', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->put(route('project-types.update', $this->typeB), [
            'name' => 'Hacked',
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
        ])
        ->assertNotFound();
});

it('org-admin cannot edit another orgs project type', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->get(route('project-types.edit', $this->typeB))
        ->assertNotFound();
});

it('super-admin sees all project types across orgs', function () {
    $response = $this->actingAs($this->superAdmin)
        ->get(route('project-types.index'));

    $response->assertOk();

    $types = collect($response->original->getData()['page']['props']['projectTypes']);
    expect($types->pluck('id')->toArray())
        ->toContain($this->typeA->id)
        ->toContain($this->typeB->id);
});

it('super-admin can duplicate a type to another org with lifecycle steps', function () {
    $this->typeA->lifecycleSteps()->createMany([
        ['order' => 1, 'label' => 'Intake', 'color' => 'indigo'],
        ['order' => 2, 'label' => 'Review', 'color' => 'blue'],
    ]);

    $this->actingAs($this->superAdmin)
        ->post(route('project-types.duplicate', $this->typeA), [
            'organization_id' => $this->orgB->id,
        ])
        ->assertRedirect();

    $copy = ProjectType::where('organization_id', $this->orgB->id)
        ->where('name', $this->typeA->name)
        ->firstOrFail();

    expect($copy->id)->not->toBe($this->typeA->id)
        ->and($copy->organization_id)->toBe($this->orgB->id)
        ->and($copy->lifecycleSteps)->toHaveCount(2)
        ->and($copy->lifecycleSteps->pluck('label')->toArray())->toBe(['Intake', 'Review']);
});

it('duplicate requires a valid organization_id', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('project-types.duplicate', $this->typeA), [
            'organization_id' => 'not-a-uuid',
        ])
        ->assertSessionHasErrors('organization_id');
});

it('org-admin cannot duplicate a project type', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('project-types.duplicate', $this->typeB), [
            'organization_id' => $this->orgA->id,
        ])
        ->assertNotFound();
});

it('org-admin cannot create a type that conflicts with another type in same org', function () {
    setPermissionsTeamId($this->orgA->id);

    $this->actingAs($this->orgAAdmin)
        ->post(route('project-types.store'), [
            'name' => 'Type A', // already exists in org A
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
        ])
        ->assertSessionHasErrors('name');
});

it('same name is allowed across different orgs', function () {
    setPermissionsTeamId($this->orgA->id);

    // typeB is in org B, same name should be allowed in org A
    $this->actingAs($this->orgAAdmin)
        ->post(route('project-types.store'), [
            'name' => 'Type B', // exists in org B, but not org A
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
        ])
        ->assertRedirect();

    expect(ProjectType::where('name', 'Type B')->where('organization_id', $this->orgA->id)->exists())->toBeTrue();
});
