<?php

use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Reads a column's values from row 2 through row (1 + $count) of a streamed xlsx
 * download's raw bytes, to assert on the actual exported row order.
 *
 * @return array<int, mixed>
 */
function readExcelColumn(string $xlsxBytes, string $column, int $count): array
{
    $tmpFile = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmpFile, $xlsxBytes);

    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile)->getActiveSheet();

    $values = [];
    for ($row = 2; $row < 2 + $count; $row++) {
        $values[] = $sheet->getCell($column.$row)->getValue();
    }

    unlink($tmpFile);

    return $values;
}

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);

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

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'action_items',
        'label' => 'Action Items',
        'is_task' => true,
        'order' => 1,
    ]);
    // Non-task type, to prove it's excluded from results.
    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'intake',
        'label' => 'Intake',
        'is_task' => false,
        'order' => 2,
    ]);

    $this->orgAdmin = User::factory()->create();
    $this->org->users()->attach($this->orgAdmin->id, ['role' => 'org-admin']);
});

it('forbids a user unrelated to the project from searching its tasks', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson(route('projects.reports.tasks', $this->project))
        ->assertNotFound();
});

it('requires authentication', function () {
    $this->getJson(route('projects.reports.tasks', $this->project))
        ->assertUnauthorized();
});

it('returns only task-type documents for the project', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'A Task', 'type' => 'action_items', 'content' => 'x']);
    Document::create(['project_id' => $this->project->id, 'name' => 'Not A Task', 'type' => 'intake', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project))
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.name'))->toBe('A Task');
});

it('includes both due dates in the response', function () {
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'A Task',
        'type' => 'action_items',
        'content' => 'x',
        'due_at' => '2026-02-10',
        'external_due_at' => '2026-02-05',
    ]);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project))
        ->assertOk();

    expect($response->json('0.due_at'))->toStartWith('2026-02-10')
        ->and($response->json('0.external_due_at'))->toStartWith('2026-02-05');
});

it('filters by assignee', function () {
    $assignee = User::factory()->create();
    $this->org->users()->attach($assignee->id);

    $assigned = Document::create(['project_id' => $this->project->id, 'name' => 'Assigned', 'type' => 'action_items', 'content' => 'x', 'assignee_id' => $assignee->id]);
    Document::create(['project_id' => $this->project->id, 'name' => 'Unassigned', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project).'?assignee[]='.$assignee->id)
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($assigned->id)
        ->and($response->json('0.assignee.name'))->toBe($assignee->name)
        ->and($response->json('0.assignee_id'))->toBe($assignee->id);
});

it('filters by multiple assignees at once, matching any of them', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $this->org->users()->attach([$first->id, $second->id]);

    $firstTask = Document::create(['project_id' => $this->project->id, 'name' => 'First', 'type' => 'action_items', 'content' => 'x', 'assignee_id' => $first->id]);
    $secondTask = Document::create(['project_id' => $this->project->id, 'name' => 'Second', 'type' => 'action_items', 'content' => 'x', 'assignee_id' => $second->id]);
    Document::create(['project_id' => $this->project->id, 'name' => 'Neither', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project)."?assignee[]={$first->id}&assignee[]={$second->id}")
        ->assertOk();

    expect(collect($response->json())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$firstTask->id, $secondTask->id])->sort()->values()->all());
});

it('filters by the unassigned sentinel alongside a real assignee', function () {
    $assignee = User::factory()->create();
    $this->org->users()->attach($assignee->id);

    $assigned = Document::create(['project_id' => $this->project->id, 'name' => 'Assigned', 'type' => 'action_items', 'content' => 'x', 'assignee_id' => $assignee->id]);
    $unassigned = Document::create(['project_id' => $this->project->id, 'name' => 'Unassigned', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project)."?assignee[]={$assignee->id}&assignee[]=unassigned")
        ->assertOk();

    expect(collect($response->json())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$assigned->id, $unassigned->id])->sort()->values()->all());
});

it('filters by a pending invitation assignee', function () {
    $invitation = OrganizationInvitation::create([
        'organization_id' => $this->org->id,
        'email' => 'invited@example.com',
        'first_name' => 'Invited',
        'last_name' => 'Person',
        'token' => str_repeat('z', 64),
        'expires_at' => now()->addDays(7),
    ]);

    $assigned = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Assigned To Invite',
        'type' => 'action_items',
        'content' => 'x',
        'pending_assignee_invitation_id' => $invitation->id,
    ]);
    Document::create(['project_id' => $this->project->id, 'name' => 'Unassigned', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project)."?assignee[]=inv:{$invitation->id}")
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($assigned->id)
        ->and($response->json('0.pending_assignee.email'))->toBe('invited@example.com')
        ->and($response->json('0.pending_assignee_invitation_id'))->toBe($invitation->id);
});

it('filters by status and priority', function () {
    // DocumentObserver::creating() always defaults a fresh task's task_status to 'todo'
    // (status isn't mass-assignable, so create() can't override this) — set the desired
    // task_status via a separate update() afterward, which that observer hook doesn't touch.
    $match = Document::create(['project_id' => $this->project->id, 'name' => 'Match', 'type' => 'action_items', 'content' => 'x', 'priority' => 'high']);
    $match->update(['task_status' => 'in_progress']);
    Document::create(['project_id' => $this->project->id, 'name' => 'No Match Status', 'type' => 'action_items', 'content' => 'x', 'priority' => 'high']);
    $noMatchPriority = Document::create(['project_id' => $this->project->id, 'name' => 'No Match Priority', 'type' => 'action_items', 'content' => 'x', 'priority' => 'low']);
    $noMatchPriority->update(['task_status' => 'in_progress']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project).'?task_status[]=in_progress&priority[]=high')
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.name'))->toBe('Match');
});

it('filters by multiple statuses and priorities at once, matching any of each', function () {
    $todo = Document::create(['project_id' => $this->project->id, 'name' => 'Todo High', 'type' => 'action_items', 'content' => 'x', 'priority' => 'high']);
    $inProgress = Document::create(['project_id' => $this->project->id, 'name' => 'In Progress Low', 'type' => 'action_items', 'content' => 'x', 'priority' => 'low']);
    $inProgress->update(['task_status' => 'in_progress']);
    $noMatch = Document::create(['project_id' => $this->project->id, 'name' => 'Todo Medium', 'type' => 'action_items', 'content' => 'x', 'priority' => 'medium']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project).'?task_status[]=todo&task_status[]=in_progress&priority[]=high&priority[]=low')
        ->assertOk();

    expect(collect($response->json())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$todo->id, $inProgress->id])->sort()->values()->all())
        ->and(collect($response->json())->pluck('id'))->not->toContain($noMatch->id);
});

it('filters by due date range', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'Too Early', 'type' => 'action_items', 'content' => 'x', 'due_at' => '2026-01-01']);
    Document::create(['project_id' => $this->project->id, 'name' => 'In Range', 'type' => 'action_items', 'content' => 'x', 'due_at' => '2026-02-15']);
    Document::create(['project_id' => $this->project->id, 'name' => 'Too Late', 'type' => 'action_items', 'content' => 'x', 'due_at' => '2026-03-01']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project).'?due_from=2026-02-01&due_to=2026-02-28')
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.name'))->toBe('In Range');
});

it('includes a task\'s tags in the response', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $task = Document::create(['project_id' => $this->project->id, 'name' => 'Tagged Task', 'type' => 'action_items', 'content' => 'x']);
    $task->categories()->attach($design->id);
    Document::create(['project_id' => $this->project->id, 'name' => 'Untagged Task', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project))
        ->assertOk();

    $tagged = collect($response->json())->firstWhere('name', 'Tagged Task');
    $untagged = collect($response->json())->firstWhere('name', 'Untagged Task');

    expect($tagged['categories'])->toHaveCount(1)
        ->and($tagged['categories'][0]['name'])->toBe('Design')
        ->and($untagged['categories'])->toBe([]);
});

it('filters by tag', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $backend = Category::create(['project_id' => $this->project->id, 'name' => 'Backend', 'color' => 'blue']);
    $designTask = Document::create(['project_id' => $this->project->id, 'name' => 'Design Task', 'type' => 'action_items', 'content' => 'x']);
    $designTask->categories()->attach($design->id);
    $backendTask = Document::create(['project_id' => $this->project->id, 'name' => 'Backend Task', 'type' => 'action_items', 'content' => 'x']);
    $backendTask->categories()->attach($backend->id);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project)."?category_id[]={$design->id}")
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.name'))->toBe('Design Task');
});

it('filters by multiple tags at once, matching any of them', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $backend = Category::create(['project_id' => $this->project->id, 'name' => 'Backend', 'color' => 'blue']);
    $marketing = Category::create(['project_id' => $this->project->id, 'name' => 'Marketing', 'color' => 'amber']);
    $designTask = Document::create(['project_id' => $this->project->id, 'name' => 'Design Task', 'type' => 'action_items', 'content' => 'x']);
    $designTask->categories()->attach($design->id);
    $backendTask = Document::create(['project_id' => $this->project->id, 'name' => 'Backend Task', 'type' => 'action_items', 'content' => 'x']);
    $backendTask->categories()->attach($backend->id);
    $marketingTask = Document::create(['project_id' => $this->project->id, 'name' => 'Marketing Task', 'type' => 'action_items', 'content' => 'x']);
    $marketingTask->categories()->attach($marketing->id);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project)."?category_id[]={$design->id}&category_id[]={$backend->id}")
        ->assertOk();

    expect(collect($response->json())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$designTask->id, $backendTask->id])->sort()->values()->all());
});

it('filters by the no-tags sentinel alongside a real tag', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $designTask = Document::create(['project_id' => $this->project->id, 'name' => 'Design Task', 'type' => 'action_items', 'content' => 'x']);
    $designTask->categories()->attach($design->id);
    $untaggedTask = Document::create(['project_id' => $this->project->id, 'name' => 'Untagged Task', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project)."?category_id[]={$design->id}&category_id[]=none")
        ->assertOk();

    expect(collect($response->json())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$designTask->id, $untaggedTask->id])->sort()->values()->all());
});

it('exports the excel with a Tags column', function () {
    $design = Category::create(['project_id' => $this->project->id, 'name' => 'Design', 'color' => 'pink']);
    $task = Document::create(['project_id' => $this->project->id, 'name' => 'Tagged Task', 'type' => 'action_items', 'content' => 'x']);
    $task->categories()->attach($design->id);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project));

    $response->assertOk();
    expect(readExcelColumn($response->streamedContent(), 'F', 1))->toBe(['Design']);
});

it('rejects an invalid priority filter', function () {
    setPermissionsTeamId($this->org->id);

    $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project).'?priority[]=extreme')
        ->assertUnprocessable();
});

// --- Filter preferences ---

it('returns null filters when nothing has been saved yet', function () {
    setPermissionsTeamId($this->org->id);

    $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks.filters', $this->project))
        ->assertOk()
        ->assertJson(['filters' => null]);
});

it('saves and returns the current user\'s filter preferences for a project', function () {
    setPermissionsTeamId($this->org->id);

    $this->actingAs($this->orgAdmin)
        ->putJson(route('projects.reports.tasks.filters.update', $this->project), [
            'task_status' => ['todo', 'in_progress'],
            'priority' => ['high'],
        ])
        ->assertOk();

    $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks.filters', $this->project))
        ->assertOk()
        ->assertJson(['filters' => ['task_status' => ['todo', 'in_progress'], 'priority' => ['high']]]);
});

it('overwrites previously saved filter preferences rather than merging them', function () {
    setPermissionsTeamId($this->org->id);

    $this->actingAs($this->orgAdmin)
        ->putJson(route('projects.reports.tasks.filters.update', $this->project), ['priority' => ['high']])
        ->assertOk();

    $this->actingAs($this->orgAdmin)
        ->putJson(route('projects.reports.tasks.filters.update', $this->project), ['task_status' => ['todo']])
        ->assertOk();

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks.filters', $this->project))
        ->assertOk();

    expect($response->json('filters'))->toBe(['task_status' => ['todo']]);
});

it('scopes saved filter preferences per user, not shared across the project', function () {
    $otherAdmin = User::factory()->create();
    $this->org->users()->attach($otherAdmin->id, ['role' => 'org-admin']);

    setPermissionsTeamId($this->org->id);

    $this->actingAs($this->orgAdmin)
        ->putJson(route('projects.reports.tasks.filters.update', $this->project), ['priority' => ['high']])
        ->assertOk();

    $this->actingAs($otherAdmin)
        ->getJson(route('projects.reports.tasks.filters', $this->project))
        ->assertOk()
        ->assertJson(['filters' => null]);
});

it('deletes saved filter preferences on reset', function () {
    setPermissionsTeamId($this->org->id);

    $this->actingAs($this->orgAdmin)
        ->putJson(route('projects.reports.tasks.filters.update', $this->project), ['priority' => ['high']])
        ->assertOk();

    $this->actingAs($this->orgAdmin)
        ->deleteJson(route('projects.reports.tasks.filters.destroy', $this->project))
        ->assertOk();

    $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks.filters', $this->project))
        ->assertOk()
        ->assertJson(['filters' => null]);
});

it('forbids a user unrelated to the project from reading or saving its filter preferences', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->getJson(route('projects.reports.tasks.filters', $this->project))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->putJson(route('projects.reports.tasks.filters.update', $this->project), ['priority' => ['high']])
        ->assertNotFound();
});

// --- Exports ---

it('exports the filtered tasks as a pdf', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'Exportable Task', 'type' => 'action_items', 'content' => 'Some details here', 'priority' => 'high']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportPdf', $this->project).'?priority[]=high');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('exports the excel sorted the same way as requested, matching the on-screen table sort', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'Charlie', 'type' => 'action_items', 'content' => 'x']);
    Document::create(['project_id' => $this->project->id, 'name' => 'Alpha', 'type' => 'action_items', 'content' => 'x']);
    Document::create(['project_id' => $this->project->id, 'name' => 'Bravo', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project).'?sort_by=name&sort_dir=asc');
    $response->assertOk();

    $names = readExcelColumn($response->streamedContent(), 'C', 3);

    expect($names)->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('exports the excel sorted by priority', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'High Task', 'type' => 'action_items', 'content' => 'x', 'priority' => 'high']);
    Document::create(['project_id' => $this->project->id, 'name' => 'Medium Task', 'type' => 'action_items', 'content' => 'x', 'priority' => 'medium']);
    Document::create(['project_id' => $this->project->id, 'name' => 'Low Task', 'type' => 'action_items', 'content' => 'x', 'priority' => 'low']);

    setPermissionsTeamId($this->org->id);

    $ascending = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project).'?sort_by=priority&sort_dir=asc');
    $ascending->assertOk();
    expect(readExcelColumn($ascending->streamedContent(), 'C', 3))->toBe(['Low Task', 'Medium Task', 'High Task']);

    $descending = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project).'?sort_by=priority&sort_dir=desc');
    $descending->assertOk();
    expect(readExcelColumn($descending->streamedContent(), 'C', 3))->toBe(['High Task', 'Medium Task', 'Low Task']);
});

it('exports the excel sorted by due date with unset due dates always last, regardless of direction', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'Later Task', 'type' => 'action_items', 'content' => 'x', 'due_at' => '2026-05-01']);
    Document::create(['project_id' => $this->project->id, 'name' => 'No Due Date Task', 'type' => 'action_items', 'content' => 'x']);
    Document::create(['project_id' => $this->project->id, 'name' => 'Earlier Task', 'type' => 'action_items', 'content' => 'x', 'due_at' => '2026-04-01']);

    setPermissionsTeamId($this->org->id);

    $ascending = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project).'?sort_by=due_at&sort_dir=asc');
    $ascending->assertOk();
    expect(readExcelColumn($ascending->streamedContent(), 'C', 3))->toBe(['Earlier Task', 'Later Task', 'No Due Date Task']);

    $descending = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project).'?sort_by=due_at&sort_dir=desc');
    $descending->assertOk();
    expect(readExcelColumn($descending->streamedContent(), 'C', 3))->toBe(['Later Task', 'Earlier Task', 'No Due Date Task']);
});

it('exports the pdf with both due-date columns and the details column when the org uses external due dates', function () {
    $this->org->update(['uses_external_due_dates' => true]);
    Document::create([
        'project_id' => $this->project->id,
        'name' => 'Exportable Task',
        'type' => 'action_items',
        'content' => 'Some details here',
        'due_at' => '2026-05-01',
        'external_due_at' => '2026-04-25',
    ]);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportPdf', $this->project).'?include_details=1');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('exports the filtered tasks as a word document', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'Exportable Task', 'type' => 'action_items', 'content' => 'Some details here']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportWord', $this->project));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
});

it('exports a valid word document when a task name contains an unescaped ampersand', function () {
    // Raw "&" is invalid inside XML text content unless escaped; PhpWord's Settings
    // defaults output escaping to *off*, so without AppServiceProvider explicitly
    // enabling it, this produces a document.xml Word refuses to open.
    Document::create(['project_id' => $this->project->id, 'name' => 'Draft approved Q&A responses', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportWord', $this->project));

    $response->assertOk();

    $tmpFile = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($tmpFile, $response->streamedContent());

    // Throws if word/document.xml isn't well-formed XML - proves the file is valid,
    // not just that a response was returned.
    $loaded = \PhpOffice\PhpWord\IOFactory::load($tmpFile);
    unlink($tmpFile);

    expect($loaded)->toBeInstanceOf(\PhpOffice\PhpWord\PhpWord::class);
});

it('exports the filtered tasks as an excel workbook, with the details column only when requested', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'Exportable Task', 'type' => 'action_items', 'content' => 'Some details here']);

    setPermissionsTeamId($this->org->id);

    $withoutDetails = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project));
    $withoutDetails->assertOk();
    expect($withoutDetails->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $withDetails = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project).'?include_details=1');
    $withDetails->assertOk();

    // The included-details export is a strictly larger file (extra column of content)
    // than the same rows without it — a lightweight proxy for "the column was added"
    // without parsing the xlsx binary in the test itself. streamedContent() (not
    // getContent()) is required to actually capture a StreamedResponse's output here.
    expect(strlen($withDetails->streamedContent()))->toBeGreaterThan(strlen($withoutDetails->streamedContent()));
});

it('forbids exporting for a user unrelated to the project', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->get(route('projects.reports.tasks.exportPdf', $this->project))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->get(route('projects.reports.tasks.exportWord', $this->project))
        ->assertNotFound();

    $this->actingAs($outsider)
        ->get(route('projects.reports.tasks.exportExcel', $this->project))
        ->assertNotFound();
});

// --- Sub-projects ---

it('includes a sub-project\'s tasks in the search results, tagged with their project name', function () {
    $subproject = Project::create(['name' => 'Sub Project', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);

    Document::create(['project_id' => $this->project->id, 'name' => 'Parent Task', 'type' => 'action_items', 'content' => 'x']);
    Document::create(['project_id' => $subproject->id, 'name' => 'Sub Task', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project))
        ->assertOk();

    $tasks = collect($response->json())->keyBy('name');
    expect($tasks)->toHaveCount(2)
        ->and($tasks['Parent Task']['project_name'])->toBe('Test Project')
        ->and($tasks['Sub Task']['project_name'])->toBe('Sub Project');
});

it('excludes tasks from an unrelated project (not a sub-project of this one)', function () {
    $unrelated = Project::create(['name' => 'Unrelated Project', 'client_id' => $this->client->id]);
    Document::create(['project_id' => $unrelated->id, 'name' => 'Unrelated Task', 'type' => 'action_items', 'content' => 'x']);
    Document::create(['project_id' => $this->project->id, 'name' => 'Own Task', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project))
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.name'))->toBe('Own Task');
});

it('filters search results down to a single sub-project via project_id', function () {
    $subproject = Project::create(['name' => 'Sub Project', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);

    Document::create(['project_id' => $this->project->id, 'name' => 'Parent Task', 'type' => 'action_items', 'content' => 'x']);
    Document::create(['project_id' => $subproject->id, 'name' => 'Sub Task', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project).'?project_id[]='.$subproject->id)
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.name'))->toBe('Sub Task');
});

it('does not include a Project column in exports when the project has no sub-projects', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'Solo Task', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project));
    $response->assertOk();

    // Column A is Status (not Project) when there's nothing to disambiguate.
    $content = $response->streamedContent();
    expect(readExcelColumn($content, 'A', 1))->not->toBe(['Sub Project']);
    expect(readExcelColumn($content, 'C', 1))->toBe(['Solo Task']);
});

it('adds a leading Project column to exports once the project has a sub-project', function () {
    $subproject = Project::create(['name' => 'Sub Project', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);
    Document::create(['project_id' => $subproject->id, 'name' => 'Sub Task', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project));
    $response->assertOk();

    $content = $response->streamedContent();
    expect(readExcelColumn($content, 'A', 1))->toBe(['Sub Project'])
        ->and(readExcelColumn($content, 'D', 1))->toBe(['Sub Task']);
});

it('sorts the export by project_name', function () {
    $subproject = Project::create(['name' => 'Alpha Sub', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);
    $this->project->update(['name' => 'Zeta Parent']);

    Document::create(['project_id' => $this->project->id, 'name' => 'Parent Task', 'type' => 'action_items', 'content' => 'x']);
    Document::create(['project_id' => $subproject->id, 'name' => 'Sub Task', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportExcel', $this->project->fresh()).'?sort_by=project_name&sort_dir=asc');
    $response->assertOk();

    expect(readExcelColumn($response->streamedContent(), 'A', 2))->toBe(['Alpha Sub', 'Zeta Parent']);
});

it('exports pdf and word successfully when the project has sub-projects', function () {
    $subproject = Project::create(['name' => 'Sub Project', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);
    Document::create(['project_id' => $subproject->id, 'name' => 'Sub Task', 'type' => 'action_items', 'content' => 'x']);

    setPermissionsTeamId($this->org->id);

    $pdf = $this->actingAs($this->orgAdmin)->get(route('projects.reports.tasks.exportPdf', $this->project));
    $pdf->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf');

    $word = $this->actingAs($this->orgAdmin)->get(route('projects.reports.tasks.exportWord', $this->project));
    $word->assertOk();
    expect($word->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
});
