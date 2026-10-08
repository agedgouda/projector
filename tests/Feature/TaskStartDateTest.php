<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Reads every row (header first) of a streamed xlsx download.
 *
 * @return array<int, array<int, mixed>>
 */
function startDateExcelRows(string $xlsxBytes): array
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmpFile, $xlsxBytes);

    $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile)->getActiveSheet()->toArray();

    unlink($tmpFile);

    return $rows;
}

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

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

    DocumentTypeDefinition::create([
        'organization_id' => $this->org->id,
        'key' => 'task',
        'label' => 'Task',
        'is_task' => true,
        'order' => 1,
    ]);

    setPermissionsTeamId($this->org->id);

    $this->makeDocument = fn (array $attributes = []): Document => Document::create(array_merge([
        'project_id' => $this->project->id,
        'name' => 'A Task',
        'type' => 'task',
        'content' => 'Do it',
        'priority' => 'low',
        'task_status' => 'todo',
    ], $attributes));

    $this->patchDates = fn (Document $document, array $dates) => $this->actingAs($this->admin)
        ->patchJson(route('projects.documents.updateAttributes', [$this->project, $document]), $dates);
});

it('lets an org-admin toggle uses_task_start_dates on their organization', function () {
    expect($this->org->fresh()->uses_task_start_dates)->toBeFalse();

    $this->actingAs($this->admin)
        ->patch(route('organizations.update', $this->org), ['uses_task_start_dates' => true])
        ->assertRedirect();

    expect($this->org->fresh()->uses_task_start_dates)->toBeTrue();
});

it('shares uses_task_start_dates on the active org via orgMembership', function (bool $enabled) {
    $this->org->update(['uses_task_start_dates' => $enabled]);

    $this->actingAs($this->admin)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('orgMembership.uses_task_start_dates', $enabled));
})->with(['enabled' => true, 'disabled' => false]);

describe('when the org tracks task start dates', function () {
    beforeEach(function () {
        $this->org->update(['uses_task_start_dates' => true]);
    });

    it('saves a start date on or before the due date', function (string $startAt) {
        $task = ($this->makeDocument)(['due_at' => '2026-08-20']);

        ($this->patchDates)($task, ['start_at' => $startAt])->assertOk();

        expect($task->fresh()->start_at)->toStartWith($startAt);
    })->with(['before due' => '2026-08-10', 'same day as due' => '2026-08-20']);

    it('saves a start date on a task with no due date', function () {
        $task = ($this->makeDocument)();

        ($this->patchDates)($task, ['start_at' => '2026-08-10'])->assertOk();

        expect($task->fresh()->start_at)->toStartWith('2026-08-10');
    });

    it('rejects a start date after the stored due date', function () {
        $task = ($this->makeDocument)(['due_at' => '2026-08-20']);

        ($this->patchDates)($task, ['start_at' => '2026-08-21'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_at' => 'The start date cannot be after the due date.']);

        expect($task->fresh()->start_at)->toBeNull();
    });

    it('rejects a due date before the stored start date', function () {
        $task = ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        ($this->patchDates)($task, ['due_at' => '2026-08-05'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_at' => 'The due date cannot be before the start date.']);

        expect($task->fresh()->due_at)->toStartWith('2026-08-20');
    });

    it('accepts both dates moved together into a valid range', function () {
        $task = ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        ($this->patchDates)($task, ['start_at' => '2026-09-10', 'due_at' => '2026-09-20'])->assertOk();

        expect($task->fresh()->start_at)->toStartWith('2026-09-10');
    });

    it('allows clearing the start date', function () {
        $task = ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        ($this->patchDates)($task, ['start_at' => null])->assertOk();

        expect($task->fresh()->start_at)->toBeNull();
    });

    it('checks the start date against the internal due date, even when the org uses external due dates', function (bool $usesExternal) {
        $this->org->update(['uses_external_due_dates' => $usesExternal]);
        $task = ($this->makeDocument)(['due_at' => '2026-08-20', 'external_due_at' => '2026-08-05']);

        // A first start date (nothing to shift yet) is checked against the internal due date
        // only — the earlier external due date doesn't count.
        ($this->patchDates)($task, ['start_at' => '2026-08-25'])->assertUnprocessable();
        ($this->patchDates)($task, ['start_at' => '2026-08-10'])->assertOk();
    })->with(['external due dates on' => true, 'external due dates off' => false]);

    it('lets the external due date be set before the start date', function () {
        $this->org->update(['uses_external_due_dates' => true]);
        $task = ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        ($this->patchDates)($task, ['external_due_at' => '2026-08-05'])->assertOk();
    });

    it('moves the end date by as many days as the start date moved', function (string $newStart, string $expectedDue) {
        $task = ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        ($this->patchDates)($task, ['start_at' => $newStart])->assertOk();

        expect($task->fresh()->start_at)->toStartWith($newStart)
            ->and($task->fresh()->due_at)->toStartWith($expectedDue);
    })->with([
        'later' => ['2026-08-13', '2026-08-23'],
        'earlier' => ['2026-08-05', '2026-08-15'],
        'past the old end date' => ['2026-08-25', '2026-09-04'],
    ]);

    it('moves the external due date along with it', function () {
        $this->org->update(['uses_external_due_dates' => true]);
        $task = ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20', 'external_due_at' => '2026-08-25']);

        ($this->patchDates)($task, ['start_at' => '2026-08-12'])->assertOk();

        expect($task->fresh()->due_at)->toStartWith('2026-08-22')
            ->and($task->fresh()->external_due_at)->toStartWith('2026-08-27');
    });

    it('keeps both dates as entered when the same save changes both', function () {
        $task = ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        ($this->patchDates)($task, ['start_at' => '2026-08-12', 'due_at' => '2026-08-30'])->assertOk();

        expect($task->fresh()->due_at)->toStartWith('2026-08-30');
    });

    it('leaves the end date alone when a start date is set for the first time', function () {
        $task = ($this->makeDocument)(['due_at' => '2026-08-20']);

        ($this->patchDates)($task, ['start_at' => '2026-08-12'])->assertOk();

        expect($task->fresh()->due_at)->toStartWith('2026-08-20');
    });

    it('carries the moved end date on to the tasks that wait on it', function () {
        $task = ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);
        $follower = ($this->makeDocument)(['name' => 'Follower', 'predecessor_id' => $task->id, 'due_at' => '2026-08-25']);

        ($this->patchDates)($task, ['start_at' => '2026-08-12'])->assertOk();

        expect($follower->fresh()->start_at)->toStartWith('2026-08-22')
            ->and($follower->fresh()->due_at)->toStartWith('2026-08-27');
    });

    it('does not apply the rule to events', function () {
        $event = ($this->makeDocument)(['type' => 'event', 'due_at' => '2026-08-20']);

        ($this->patchDates)($event, ['start_at' => '2026-08-25'])->assertOk();
    });

    it('rejects creating a task whose start date is after its due date', function () {
        $this->actingAs($this->admin)
            ->postJson(route('projects.documents.store', $this->project), [
                'name' => 'New Task',
                'type' => 'task',
                'content' => 'Do the thing',
                'priority' => 'low',
                'task_status' => 'todo',
                'start_at' => '2026-08-25',
                'due_at' => '2026-08-20',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_at']);

        expect(Document::where('name', 'New Task')->exists())->toBeFalse();
    });

    it('creates a task with a valid start and due date', function () {
        $this->actingAs($this->admin)
            ->post(route('projects.documents.store', $this->project), [
                'name' => 'New Task',
                'type' => 'task',
                'content' => 'Do the thing',
                'priority' => 'low',
                'task_status' => 'todo',
                'start_at' => '2026-08-10',
                'due_at' => '2026-08-20',
            ])
            ->assertRedirect();

        expect(Document::where('name', 'New Task')->firstOrFail()->start_at)->toStartWith('2026-08-10');
    });

    it('spans a task from its start date on the calendar', function () {
        ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

        expect($items->first()['start_at'])->toStartWith('2026-08-10');
    });

    it('returns and sorts by start_at in the task report', function () {
        ($this->makeDocument)(['name' => 'Later', 'start_at' => '2026-08-15', 'due_at' => '2026-08-20']);
        ($this->makeDocument)(['name' => 'Earlier', 'start_at' => '2026-08-01', 'due_at' => '2026-08-30']);
        ($this->makeDocument)(['name' => 'No Start', 'due_at' => '2026-08-05']);

        $response = $this->actingAs($this->admin)
            ->getJson(route('projects.reports.tasks', $this->project))
            ->assertOk();

        expect(collect($response->json())->firstWhere('name', 'Earlier')['start_at'])->toStartWith('2026-08-01');

        $xlsx = $this->actingAs($this->admin)
            ->get(route('projects.reports.tasks.exportExcel', $this->project).'?sort_by=start_at&sort_dir=asc')
            ->assertOk()
            ->streamedContent();

        $rows = startDateExcelRows($xlsx);

        expect($rows[0])->toBe(['Name', 'Status', 'Assignee', 'Start Date', 'Due Date', 'Priority', 'Tags'])
            ->and(array_column(array_slice($rows, 1), 0))->toBe(['Earlier', 'Later', 'No Start'])
            ->and($rows[1][3])->toBe('08/01/2026');
    });

    it('adds a Start Date column to the task report PDF', function () {
        ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        $this->actingAs($this->admin)
            ->get(route('projects.reports.tasks.exportPdf', $this->project))
            ->assertOk();

        $html = view('pdfs.task-report', [
            'project' => $this->project,
            'client' => $this->client,
            'tasks' => $this->project->documents()->with('categories')->get(),
            'columns' => $this->project->kanbanColumns,
            'includeDetails' => false,
            'isDoneMode' => false,
            'usesExternalDueDates' => false,
            'usesTaskStartDates' => true,
            'hasSubprojects' => false,
            'projectNames' => [],
            'logoPath' => null,
            'headerImagePath' => null,
            'footerImagePath' => null,
        ])->render();

        expect($html)->toContain('<th>Start Date</th>')->toContain('08/10/2026');
    });
});

describe('when the org does not track task start dates', function () {
    it('does not validate the start date against the due date', function () {
        $task = ($this->makeDocument)(['start_at' => '2026-08-25', 'due_at' => '2026-08-30']);

        ($this->patchDates)($task, ['due_at' => '2026-08-20'])->assertOk();
    });

    it('keeps a stored task start date off the calendar', function () {
        ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);
        ($this->makeDocument)(['name' => 'An Event', 'type' => 'event', 'start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        $items = $this->project->fresh()->load(['documents.categories', 'children.documents.categories'])->calendarItems();

        expect($items->firstWhere('type', 'task')['start_at'])->toBeNull()
            ->and($items->firstWhere('type', 'event')['start_at'])->toStartWith('2026-08-10');
    });

    it('leaves the Start Date column out of the task report export', function () {
        ($this->makeDocument)(['start_at' => '2026-08-10', 'due_at' => '2026-08-20']);

        $xlsx = $this->actingAs($this->admin)
            ->get(route('projects.reports.tasks.exportExcel', $this->project))
            ->assertOk()
            ->streamedContent();

        expect(startDateExcelRows($xlsx)[0])->not->toContain('Start Date');
    });
});
