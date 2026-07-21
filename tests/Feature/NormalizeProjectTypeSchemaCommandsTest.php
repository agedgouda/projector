<?php

use App\Models\AiTemplate;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\ProjectType;
use App\Models\WorkflowStep;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('backfills document_type_definitions and workflow_steps from JSON', function () {
    $template = AiTemplate::create([
        'name' => 'Notes to Tasks',
        'type' => 'workflow',
        'system_prompt' => 'system',
        'user_prompt' => 'user',
    ]);

    $projectType = ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Notes', 'key' => 'intake', 'is_task' => false],
            ['label' => 'Action Item', 'key' => 'action_items', 'is_task' => true],
        ],
        'workflow' => [
            ['from_key' => 'intake', 'to_key' => 'action_items', 'ai_template_id' => $template->id, 'single_output' => false],
        ],
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')->assertExitCode(0);

    $definitions = DocumentTypeDefinition::whereNull('organization_id')->orderBy('order')->get();
    expect($definitions)->toHaveCount(2);
    expect($definitions[0])->key->toBe('intake')->label->toBe('Notes')->is_task->toBeFalse();
    expect($definitions[1])->key->toBe('action_items')->label->toBe('Action Item')->is_task->toBeTrue();

    $steps = WorkflowStep::where('project_type_id', $projectType->id)->get();
    expect($steps)->toHaveCount(1);
    expect($steps[0])->from_key->toBe('intake')->to_key->toBe('action_items')->ai_template_id->toBe($template->id);
});

it('is idempotent — a second run makes no further changes', function () {
    ProjectType::factory()->create([
        'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
        'workflow' => [['from_key' => 'intake', 'to_key' => 'summary', 'ai_template_id' => null]],
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')->assertExitCode(0);
    $definitionCountAfterFirstRun = DocumentTypeDefinition::count();
    $stepCountAfterFirstRun = WorkflowStep::count();

    $this->artisan('app:backfill-normalized-project-type-schema')
        ->expectsOutputToContain('0 created, 0 updated, 0 deleted')
        ->assertExitCode(0);

    expect(DocumentTypeDefinition::count())->toBe($definitionCountAfterFirstRun);
    expect(WorkflowStep::count())->toBe($stepCountAfterFirstRun);
});

it('removes stale document_type_definitions once a key is removed from every protocol using it', function () {
    $projectType = ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Notes', 'key' => 'intake', 'is_task' => false],
            ['label' => 'Old Key', 'key' => 'deprecated', 'is_task' => false],
        ],
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')->assertExitCode(0);
    expect(DocumentTypeDefinition::whereNull('organization_id')->count())->toBe(2);

    $projectType->update([
        'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')->assertExitCode(0);

    $remaining = DocumentTypeDefinition::whereNull('organization_id')->pluck('key');
    expect($remaining)->toEqual(collect(['intake']));
});

it('dedupes an identical key shared across multiple protocols without reporting a collision', function () {
    ProjectType::factory()->create([
        'name' => 'Protocol A',
        'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
    ]);
    ProjectType::factory()->create([
        'name' => 'Protocol B',
        'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')
        ->doesntExpectOutputToContain('collision')
        ->assertExitCode(0);

    expect(DocumentTypeDefinition::whereNull('organization_id')->where('key', 'intake')->count())->toBe(1);
});

it('reports a collision when two protocols define the same key differently, keeping the first-seen value', function () {
    $first = ProjectType::factory()->create([
        'name' => 'Older Protocol',
        'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
        'created_at' => now()->subDay(),
    ]);
    ProjectType::factory()->create([
        'name' => 'Newer Protocol',
        'document_schema' => [['label' => 'Meeting Notes', 'key' => 'intake', 'is_task' => false]],
        'created_at' => now(),
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')
        ->expectsOutputToContain('1 document type key collision(s) found')
        ->expectsOutputToContain('Older Protocol')
        ->expectsOutputToContain('Newer Protocol')
        ->assertExitCode(0);

    $definition = DocumentTypeDefinition::whereNull('organization_id')->where('key', 'intake')->sole();
    expect($definition->label)->toBe('Notes');
});

it('keeps separate document type catalogs per organization', function () {
    $orgA = Organization::create(['name' => 'Org A']);
    $orgB = Organization::create(['name' => 'Org B']);

    ProjectType::factory()->create([
        'organization_id' => $orgA->id,
        'document_schema' => [['label' => 'Org A Notes', 'key' => 'intake', 'is_task' => false]],
    ]);
    ProjectType::factory()->create([
        'organization_id' => $orgB->id,
        'document_schema' => [['label' => 'Org B Notes', 'key' => 'intake', 'is_task' => false]],
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')
        ->doesntExpectOutputToContain('collision')
        ->assertExitCode(0);

    expect(DocumentTypeDefinition::where('organization_id', $orgA->id)->sole()->label)->toBe('Org A Notes');
    expect(DocumentTypeDefinition::where('organization_id', $orgB->id)->sole()->label)->toBe('Org B Notes');
});

it('nulls out a dangling ai_template_id instead of failing, and reports it', function () {
    ProjectType::factory()->create([
        'name' => 'Broken Reference Type',
        'workflow' => [
            ['from_key' => 'intake', 'to_key' => 'summary', 'ai_template_id' => 999999],
        ],
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')
        ->expectsOutputToContain('1 workflow step(s) reference an ai_template_id that no longer exists')
        ->expectsOutputToContain('Broken Reference Type')
        ->assertExitCode(0);

    $step = WorkflowStep::first();
    expect($step->ai_template_id)->toBeNull();
});

it('verify command passes after a correct backfill', function () {
    ProjectType::factory()->create([
        'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
        'workflow' => [['from_key' => 'intake', 'to_key' => 'summary', 'ai_template_id' => null]],
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')->assertExitCode(0);

    $this->artisan('app:verify-normalized-project-type-schema')
        ->expectsOutputToContain('OK')
        ->assertExitCode(0);
});

it('verify command fails when normalized data has drifted from the JSON', function () {
    $projectType = ProjectType::factory()->create([
        'document_schema' => [['label' => 'Notes', 'key' => 'intake', 'is_task' => false]],
    ]);

    $this->artisan('app:backfill-normalized-project-type-schema')->assertExitCode(0);

    // Simulate drift: JSON changes without the backfill being re-run.
    $projectType->update([
        'document_schema' => [['label' => 'Notes (renamed)', 'key' => 'intake', 'is_task' => false]],
    ]);

    $this->artisan('app:verify-normalized-project-type-schema')
        ->expectsOutputToContain('mismatch')
        ->assertExitCode(1);
});
