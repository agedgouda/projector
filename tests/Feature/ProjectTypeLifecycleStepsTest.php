<?php

use App\Models\LifecycleStep;
use App\Models\ProjectType;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super-admin');

    $this->projectType = ProjectType::factory()->create();
});

it('creates lifecycle steps when storing a project type', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('project-types.store'), [
            'name' => 'New Protocol',
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
            'lifecycle_steps' => [
                ['order' => 1, 'label' => 'Intake', 'color' => 'indigo'],
                ['order' => 2, 'label' => 'In Progress', 'color' => 'blue'],
                ['order' => 3, 'label' => 'Completed', 'color' => 'green'],
            ],
        ])
        ->assertRedirect();

    $created = ProjectType::where('name', 'New Protocol')->firstOrFail();

    expect($created->lifecycleSteps)->toHaveCount(3)
        ->and($created->lifecycleSteps->pluck('label')->toArray())->toBe(['Intake', 'In Progress', 'Completed']);
});

it('creates lifecycle steps when updating a project type', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('project-types.update', $this->projectType), [
            'name' => $this->projectType->name,
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
            'lifecycle_steps' => [
                ['order' => 1, 'label' => 'Draft', 'color' => 'slate'],
                ['order' => 2, 'label' => 'Review', 'color' => 'amber'],
            ],
        ])
        ->assertRedirect();

    expect($this->projectType->fresh()->lifecycleSteps)->toHaveCount(2);
});

it('updates existing lifecycle steps by id', function () {
    $step = LifecycleStep::create([
        'project_type_id' => $this->projectType->id,
        'order' => 1,
        'label' => 'Old Label',
        'color' => 'slate',
    ]);

    $this->actingAs($this->superAdmin)
        ->put(route('project-types.update', $this->projectType), [
            'name' => $this->projectType->name,
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
            'lifecycle_steps' => [
                ['id' => $step->id, 'order' => 1, 'label' => 'New Label', 'color' => 'green'],
            ],
        ])
        ->assertRedirect();

    expect($step->fresh()->label)->toBe('New Label')
        ->and($step->fresh()->color)->toBe('green');
});

it('deletes lifecycle steps removed from the payload', function () {
    $kept = LifecycleStep::create(['project_type_id' => $this->projectType->id, 'order' => 1, 'label' => 'Keep', 'color' => 'blue']);
    $deleted = LifecycleStep::create(['project_type_id' => $this->projectType->id, 'order' => 2, 'label' => 'Remove', 'color' => 'red']);

    $this->actingAs($this->superAdmin)
        ->put(route('project-types.update', $this->projectType), [
            'name' => $this->projectType->name,
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
            'lifecycle_steps' => [
                ['id' => $kept->id, 'order' => 1, 'label' => 'Keep', 'color' => 'blue'],
            ],
        ])
        ->assertRedirect();

    expect(LifecycleStep::find($kept->id))->not->toBeNull()
        ->and(LifecycleStep::find($deleted->id))->toBeNull();
});

it('clears all lifecycle steps when an empty array is submitted', function () {
    LifecycleStep::create(['project_type_id' => $this->projectType->id, 'order' => 1, 'label' => 'Step A', 'color' => 'indigo']);
    LifecycleStep::create(['project_type_id' => $this->projectType->id, 'order' => 2, 'label' => 'Step B', 'color' => 'blue']);

    $this->actingAs($this->superAdmin)
        ->put(route('project-types.update', $this->projectType), [
            'name' => $this->projectType->name,
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
            'lifecycle_steps' => [],
        ])
        ->assertRedirect();

    expect($this->projectType->fresh()->lifecycleSteps)->toHaveCount(0);
});

it('rejects lifecycle steps with missing required fields', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('project-types.update', $this->projectType), [
            'name' => $this->projectType->name,
            'icon' => 'Briefcase',
            'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
            'lifecycle_steps' => [
                ['order' => 1], // missing label
            ],
        ])
        ->assertSessionHasErrors('lifecycle_steps.0.label');
});
