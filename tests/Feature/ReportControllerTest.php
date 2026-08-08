<?php

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
        ->getJson(route('projects.reports.tasks', $this->project).'?assignee='.$assignee->id)
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($assigned->id)
        ->and($response->json('0.assignee.name'))->toBe($assignee->name);
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
        ->getJson(route('projects.reports.tasks', $this->project)."?assignee=inv:{$invitation->id}")
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($assigned->id)
        ->and($response->json('0.pending_assignee.email'))->toBe('invited@example.com');
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
        ->getJson(route('projects.reports.tasks', $this->project).'?task_status=in_progress&priority=high')
        ->assertOk();

    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.name'))->toBe('Match');
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

it('rejects an invalid priority filter', function () {
    setPermissionsTeamId($this->org->id);

    $this->actingAs($this->orgAdmin)
        ->getJson(route('projects.reports.tasks', $this->project).'?priority=extreme')
        ->assertUnprocessable();
});

// --- Exports ---

it('exports the filtered tasks as a pdf', function () {
    Document::create(['project_id' => $this->project->id, 'name' => 'Exportable Task', 'type' => 'action_items', 'content' => 'Some details here', 'priority' => 'high']);

    setPermissionsTeamId($this->org->id);

    $response = $this->actingAs($this->orgAdmin)
        ->get(route('projects.reports.tasks.exportPdf', $this->project).'?priority=high');

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
