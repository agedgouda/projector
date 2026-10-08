<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org', 'uses_task_start_dates' => true]);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    DocumentTypeDefinition::create([
        'organization_id' => $this->org->id,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);

    setPermissionsTeamId($this->org->id);

    $this->task = fn (string $name, array $attributes = []): Document => Document::create(array_merge([
        'project_id' => $this->project->id,
        'name' => $name,
        'type' => 'task',
        'content' => 'Do it',
        'priority' => 'low',
        'task_status' => 'todo',
    ], $attributes));

    $this->patch = fn (Document $document, array $fields) => $this->actingAs($this->admin)
        ->patchJson(route('projects.documents.updateAttributes', [$document->project_id, $document]), $fields);

    $this->dates = fn (Document $document): array => [
        substr((string) $document->fresh()->start_at, 0, 10) ?: null,
        substr((string) $document->fresh()->due_at, 0, 10) ?: null,
    ];
});

describe('linking', function () {
    it('starts a follower the day its predecessor ends, keeping its duration', function () {
        $design = ($this->task)('Design', ['start_at' => '2026-09-01', 'due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['start_at' => '2026-09-01', 'due_at' => '2026-09-05']);

        ($this->patch)($build, ['predecessor_id' => $design->id])->assertOk();

        expect(($this->dates)($build))->toBe(['2026-09-10', '2026-09-14']);
    });

    it('pulls a follower with no start forward so its end is not before its start', function () {
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['due_at' => '2026-09-05']);

        ($this->patch)($build, ['predecessor_id' => $design->id])->assertOk();

        expect(($this->dates)($build))->toBe(['2026-09-10', '2026-09-10']);
    });

    it('leaves a follower without a start when its predecessor has no end', function () {
        $design = ($this->task)('Design');
        $build = ($this->task)('Build', ['start_at' => '2026-09-01', 'due_at' => '2026-09-05']);

        ($this->patch)($build, ['predecessor_id' => $design->id])->assertOk();

        expect(($this->dates)($build))->toBe([null, '2026-09-05']);
    });

    it('rejects links that would loop', function () {
        $a = ($this->task)('A');
        $b = ($this->task)('B', ['predecessor_id' => $a->id]);
        $c = ($this->task)('C', ['predecessor_id' => $b->id]);

        ($this->patch)($a, ['predecessor_id' => $c->id])->assertUnprocessable()->assertJsonValidationErrors('predecessor_id');
        ($this->patch)($a, ['predecessor_id' => $a->id])->assertUnprocessable()->assertJsonValidationErrors('predecessor_id');
    });

    it('rejects a task from another project, even a sub-project', function () {
        $child = Project::create(['name' => 'Sub', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);
        $elsewhere = Document::create(['project_id' => $child->id, 'name' => 'Other', 'type' => 'task', 'content' => 'x', 'priority' => 'low', 'task_status' => 'todo']);
        $build = ($this->task)('Build');

        ($this->patch)($build, ['predecessor_id' => $elsewhere->id])->assertUnprocessable()->assertJsonValidationErrors('predecessor_id');
    });

    it('rejects waiting on something that is not a task', function () {
        $event = ($this->task)('Launch', ['type' => 'event', 'due_at' => '2026-09-10']);
        $build = ($this->task)('Build');

        ($this->patch)($build, ['predecessor_id' => $event->id])->assertUnprocessable()->assertJsonValidationErrors('predecessor_id');
    });

    it('rejects linking when the organization does not track task start dates', function () {
        $this->org->update(['uses_task_start_dates' => false]);
        $design = ($this->task)('Design');
        $build = ($this->task)('Build');

        ($this->patch)($build, ['predecessor_id' => $design->id])->assertUnprocessable()->assertJsonValidationErrors('predecessor_id');
    });

    it('re-dates a moved task\'s whole subtree when it is re-linked to a different task', function () {
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $research = ($this->task)('Research', ['due_at' => '2026-09-20']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);
        $test = ($this->task)('Test', ['predecessor_id' => $build->id, 'due_at' => '2026-09-17']);

        $response = ($this->patch)($build, ['predecessor_id' => $research->id])->assertOk();

        expect(($this->dates)($build))->toBe(['2026-09-20', '2026-09-24'])
            ->and(($this->dates)($test))->toBe(['2026-09-24', '2026-09-27'])
            ->and($test->fresh()->predecessor_id)->toBe($build->id)
            ->and(collect($response->json('cascaded'))->pluck('id')->all())->toBe([$test->id]);
    });

    it('unlinks without changing dates', function () {
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);

        ($this->patch)($build, ['predecessor_id' => null])->assertOk();

        expect($build->fresh()->predecessor_id)->toBeNull()
            ->and(($this->dates)($build))->toBe(['2026-09-10', '2026-09-14']);
    });
});

describe('cascading', function () {
    it('shifts every task down the chain when an end date moves, keeping durations', function () {
        $design = ($this->task)('Design', ['start_at' => '2026-09-01', 'due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);
        $test = ($this->task)('Test', ['predecessor_id' => $build->id, 'due_at' => '2026-09-20']);
        $docs = ($this->task)('Docs', ['predecessor_id' => $design->id, 'due_at' => '2026-09-12']);

        $response = ($this->patch)($design, ['due_at' => '2026-09-13'])->assertOk();

        expect(($this->dates)($build))->toBe(['2026-09-13', '2026-09-17'])
            ->and(($this->dates)($test))->toBe(['2026-09-17', '2026-09-23'])
            ->and(($this->dates)($docs))->toBe(['2026-09-13', '2026-09-15'])
            ->and(collect($response->json('cascaded'))->pluck('id')->sort()->values()->all())
            ->toBe(collect([$build->id, $test->id, $docs->id])->sort()->values()->all());
    });

    it('follows the internal due date, never the external one', function () {
        $this->org->update(['uses_external_due_dates' => true]);
        $design = ($this->task)('Design', ['due_at' => '2026-09-10', 'external_due_at' => '2026-09-12']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14', 'external_due_at' => '2026-09-16']);

        expect(($this->dates)($build)[0])->toBe('2026-09-10');

        ($this->patch)($design, ['external_due_at' => '2026-09-20'])->assertOk();
        expect(($this->dates)($build)[0])->toBe('2026-09-10');

        ($this->patch)($design, ['due_at' => '2026-09-11'])->assertOk();
        $build->refresh();
        expect(($this->dates)($build))->toBe(['2026-09-11', '2026-09-15'])
            ->and(substr((string) $build->external_due_at, 0, 10))->toBe('2026-09-17');
    });

    it('blanks followers\' start when the predecessor\'s end is cleared, keeping their end', function () {
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);

        ($this->patch)($design, ['due_at' => null])->assertOk();

        expect(($this->dates)($build))->toBe([null, '2026-09-14']);
    });

    it('does nothing while the organization does not track task start dates, and keeps the link', function () {
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);
        $this->org->update(['uses_task_start_dates' => false]);

        ($this->patch)($design, ['due_at' => '2026-09-20'])->assertOk();

        expect(($this->dates)($build))->toBe(['2026-09-10', '2026-09-14'])
            ->and($build->fresh()->predecessor_id)->toBe($design->id);
    });

    it('cascades from saves outside the controller too', function () {
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);

        $design->update(['due_at' => '2026-09-11']);

        expect(($this->dates)($build))->toBe(['2026-09-11', '2026-09-15']);
    });
});

describe('locked start date', function () {
    it('blocks changing or clearing a linked task\'s start date', function (?string $value) {
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);

        ($this->patch)($build, ['start_at' => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_at' => 'This task starts when "Design" ends, so its start date can\'t be changed directly.']);
    })->with(['changed' => '2026-09-08', 'cleared' => null]);

    it('still lets a linked task\'s other fields and end date change', function () {
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);

        ($this->patch)($build, ['due_at' => '2026-09-16', 'priority' => 'high'])->assertOk();

        expect(($this->dates)($build))->toBe(['2026-09-10', '2026-09-16']);
    });

    it('lets an unlinked task\'s start date change', function () {
        $build = ($this->task)('Build', ['due_at' => '2026-09-14']);

        ($this->patch)($build, ['start_at' => '2026-09-08'])->assertOk();
    });
});

describe('leaving a chain', function () {
    it('unlinks followers of a deleted task and keeps their dates', function () {
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);

        $design->delete();

        expect($build->fresh()->predecessor_id)->toBeNull()
            ->and(($this->dates)($build))->toBe(['2026-09-10', '2026-09-14']);
    });

    it('unlinks a task moved to another board, both ways', function () {
        $other = Project::create(['name' => 'Other', 'client_id' => $this->client->id]);
        $design = ($this->task)('Design', ['due_at' => '2026-09-10']);
        $build = ($this->task)('Build', ['predecessor_id' => $design->id, 'due_at' => '2026-09-14']);
        $test = ($this->task)('Test', ['predecessor_id' => $build->id]);

        $build->update(['project_id' => $other->id]);

        expect($build->fresh()->predecessor_id)->toBeNull()
            ->and($test->fresh()->predecessor_id)->toBeNull()
            ->and(($this->dates)($test)[0])->toBe('2026-09-14');
    });
});

describe('links endpoint', function () {
    it('lists the task\'s links and only the tasks it can be linked to', function () {
        $a = ($this->task)('A');
        $b = ($this->task)('B', ['predecessor_id' => $a->id]);
        $c = ($this->task)('C', ['predecessor_id' => $b->id]);
        $d = ($this->task)('D');
        ($this->task)('An Event', ['type' => 'event']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('projects.documents.links', [$this->project, $b]))
            ->assertOk()
            ->assertJsonPath('predecessor.id', $a->id)
            ->assertJsonPath('followers.0.id', $c->id);

        expect(collect($response->json('predecessor_options'))->pluck('name')->all())->toBe(['A', 'D'])
            ->and(collect($response->json('follower_options'))->pluck('name')->all())->toBe(['D']);
    });

    it('is not available when the organization does not track task start dates', function () {
        $this->org->update(['uses_task_start_dates' => false]);
        $a = ($this->task)('A');

        $this->actingAs($this->admin)
            ->getJson(route('projects.documents.links', [$this->project, $a]))
            ->assertNotFound();
    });
});

describe('exports', function () {
    beforeEach(function () {
        $this->design = ($this->task)('Design', ['start_at' => '2026-09-01', 'due_at' => '2026-09-10']);
        $this->build = ($this->task)('Build', ['predecessor_id' => $this->design->id, 'due_at' => '2026-09-14']);
        $this->test = ($this->task)('Test', ['predecessor_id' => $this->build->id, 'due_at' => '2026-09-16']);
        $this->docs = ($this->task)('Docs', ['predecessor_id' => $this->design->id, 'due_at' => '2026-09-12']);
        $this->solo = ($this->task)('Solo', ['start_at' => '2026-08-20', 'due_at' => '2026-08-25']);

        $this->excelRows = function (string $query): array {
            $bytes = $this->actingAs($this->admin)
                ->get(route('projects.reports.tasks.exportExcel', $this->project).$query)
                ->assertOk()
                ->streamedContent();
            $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($tmp, $bytes);
            $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet()->toArray();
            unlink($tmp);

            return $rows;
        };
    });

    it('nests names in chain order', function () {
        $rows = ($this->excelRows)('?sort_by=chain');

        expect($rows[0])->toBe(['Name', 'Status', 'Assignee', 'Start Date', 'Due Date', 'Priority', 'Tags'])
            ->and(array_column(array_slice($rows, 1), 0))->toBe([
                'Solo',
                'Design',
                // Build and Docs both start the day Design ends, so they go by name.
                'Build',
                'Test',
                'Docs',
            ]);
    });

    it('does not indent when sorted by a column', function () {
        $rows = ($this->excelRows)('?sort_by=name&sort_dir=asc');

        expect(array_column(array_slice($rows, 1), 0))->toBe(['Build', 'Design', 'Docs', 'Solo', 'Test']);
    });

    it('nests the PDF the same way, without the external due column', function () {
        $this->org->update(['uses_external_due_dates' => true]);

        $this->actingAs($this->admin)
            ->get(route('projects.reports.tasks.exportPdf', $this->project).'?sort_by=chain')
            ->assertOk();

        [$tasks, $includeDetails, $projectNames, $mode] = app(\App\Services\Reports\TaskReportBuilder::class)
            ->tasksForExport(['sort_by' => 'chain'], $this->project);
        $html = view('pdfs.task-report', [
            'project' => $this->project,
            'client' => $this->client,
            'tasks' => $tasks,
            'columns' => $this->project->kanbanColumns,
            'includeDetails' => $includeDetails,
            'isDoneMode' => $mode === 'done',
            'usesTaskStartDates' => true,
            'hasSubprojects' => count($projectNames) > 1,
            'projectNames' => $projectNames,
            'logoPath' => null,
            'headerImagePath' => null,
            'footerImagePath' => null,
        ])->render();

        expect($html)->not->toContain('External Due')
            ->not->toContain('↳')
            ->toContain('padding-left: 36px');
    });

    it('uses the same columns in Word and indents nested names as paragraphs', function () {
        $bytes = $this->actingAs($this->admin)
            ->get(route('projects.reports.tasks.exportWord', $this->project).'?sort_by=chain')
            ->assertOk()
            ->streamedContent();

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($tmp);

        $text = strip_tags(str_replace('</w:p>', "\n", $xml));
        $headerOrder = array_values(array_filter(
            ['Name', 'Status', 'Assignee', 'Start Date', 'Due Date', 'Priority', 'Tags'],
            fn (string $header) => str_contains($text, $header),
        ));

        expect($headerOrder)->toBe(['Name', 'Status', 'Assignee', 'Start Date', 'Due Date', 'Priority', 'Tags'])
            ->and(strpos($text, "Name\n"))->toBeLessThan(strpos($text, "Status\n"))
            ->and($xml)->toContain('w:left="240"')
            ->toContain('w:left="480"')
            ->not->toContain('Task Name');
    });

    it('ignores chain order for organizations that do not track task start dates', function () {
        $this->org->update(['uses_task_start_dates' => false]);

        $rows = ($this->excelRows)('?sort_by=chain');

        expect($rows[0])->toBe(['Name', 'Status', 'Assignee', 'Due Date', 'Priority', 'Tags'])
            ->and(array_column(array_slice($rows, 1), 0))->toBe(['Solo', 'Design', 'Docs', 'Build', 'Test']);
    });
});
