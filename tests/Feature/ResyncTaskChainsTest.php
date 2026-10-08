<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org', 'uses_task_start_dates' => true]);
    $client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $client->id]);

    DocumentTypeDefinition::create([
        'organization_id' => $this->org->id,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);

    $this->task = fn (string $name, array $attributes = []): Document => Document::create(array_merge([
        'project_id' => $this->project->id,
        'name' => $name,
        'type' => 'task',
        'content' => 'Do it',
        'priority' => 'low',
        'task_status' => 'todo',
    ], $attributes));

    // Writes straight to the table, skipping TaskChainObserver — how a chain ends up out of
    // step (links made under an earlier rule, or while the org had start dates turned off).
    $this->setStale = fn (Document $document, array $values) => Document::query()->whereKey($document->id)->update($values);

    $this->dates = fn (Document $document): array => [
        substr((string) $document->fresh()->start_at, 0, 10) ?: null,
        substr((string) $document->fresh()->due_at, 0, 10) ?: null,
    ];

    $this->design = ($this->task)('Design', ['due_at' => '2026-09-10']);
    $this->build = ($this->task)('Build', ['predecessor_id' => $this->design->id, 'due_at' => '2026-09-14']);
    $this->test = ($this->task)('Test', ['predecessor_id' => $this->build->id, 'due_at' => '2026-09-20']);

    ($this->setStale)($this->build, ['start_at' => '2026-09-12', 'due_at' => '2026-09-16']);
    ($this->setStale)($this->test, ['start_at' => '2026-09-30', 'due_at' => '2026-10-02']);
});

it('reports what would change on a dry run without saving anything', function () {
    $this->artisan('app:resync-task-chains', ['--dry-run' => true])
        ->expectsOutputToContain('Would re-date "Build"')
        ->expectsOutputToContain('Would re-date "Test"')
        ->expectsOutputToContain('2 linked task(s) checked; 2 would be re-dated')
        ->assertSuccessful();

    expect(($this->dates)($this->build))->toBe(['2026-09-12', '2026-09-16']);
});

it('re-dates every follower down the chain, keeping durations', function () {
    $this->artisan('app:resync-task-chains')
        ->expectsOutputToContain('2 linked task(s) checked; 2 re-dated')
        ->assertSuccessful();

    expect(($this->dates)($this->build))->toBe(['2026-09-10', '2026-09-14'])
        ->and(($this->dates)($this->test))->toBe(['2026-09-14', '2026-09-16']);
});

it('changes nothing when run again', function () {
    $this->artisan('app:resync-task-chains')->assertSuccessful();

    $this->artisan('app:resync-task-chains')
        ->expectsOutputToContain('2 linked task(s) checked; 0 re-dated')
        ->assertSuccessful();
});

it('leaves organizations that do not track task start dates alone', function () {
    $this->org->update(['uses_task_start_dates' => false]);

    $this->artisan('app:resync-task-chains')
        ->expectsOutputToContain('0 linked task(s) checked; 0 re-dated')
        ->assertSuccessful();

    expect(($this->dates)($this->build))->toBe(['2026-09-12', '2026-09-16']);
});
