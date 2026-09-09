<?php

use App\Models\Client;
use App\Models\Organization;
use App\Models\PendingImport;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    $this->member = User::factory()->create();
    $this->org->users()->attach($this->member->id, ['role' => 'member']);
    $this->client->users()->attach($this->member->id);

    setPermissionsTeamId($this->org->id);
});

function createPendingImportWithFile(Project $project, string $csv = "Name,Start Date\nTeam Offsite,2026-09-10"): PendingImport
{
    $pendingImport = PendingImport::create([
        'project_id' => $project->id,
        'original_filename' => 'export.csv',
        'source_type' => 'spreadsheet',
    ]);

    $tmpPath = tempnam(sys_get_temp_dir(), 'pending_import_test').'.csv';
    file_put_contents($tmpPath, $csv);
    $uploadedFile = new UploadedFile($tmpPath, 'export.csv', 'text/csv', null, true);
    $pendingImport->addMedia($uploadedFile)->toMediaCollection('file');
    @unlink($tmpPath);

    return $pendingImport;
}

function createPendingImportWithDocx(Project $project, string $line = 'Team Offsite on 2026-09-10.'): PendingImport
{
    $pendingImport = PendingImport::create([
        'project_id' => $project->id,
        'original_filename' => 'schedule.docx',
        'source_type' => 'text',
    ]);

    $phpWord = new \PhpOffice\PhpWord\PhpWord;
    $phpWord->addSection()->addText($line);

    $tmpPath = tempnam(sys_get_temp_dir(), 'pending_import_test').'.docx';
    \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tmpPath);
    $uploadedFile = new UploadedFile($tmpPath, 'schedule.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    $pendingImport->addMedia($uploadedFile)->toMediaCollection('file');
    @unlink($tmpPath);

    return $pendingImport;
}

// ── show() ───────────────────────────────────────────────────────────────────

it('re-parses the stored file for a manageable project', function () {
    $pendingImport = createPendingImportWithFile($this->project);

    $response = $this->actingAs($this->admin)->getJson(route('import.pending.show', $pendingImport));

    $response->assertOk();
    expect($response->json('source_mode'))->toBe('spreadsheet')
        ->and($response->json('headers'))->toBe(['Name', 'Start Date'])
        ->and($response->json('rows.0.0'))->toBe('Team Offsite')
        ->and($response->json('original_filename'))->toBe('export.csv')
        ->and($response->json('project_id'))->toBe($this->project->id);
});

it('re-extracts the stored docx\'s text for a manageable project', function () {
    $pendingImport = createPendingImportWithDocx($this->project);

    $response = $this->actingAs($this->admin)->getJson(route('import.pending.show', $pendingImport));

    $response->assertOk();
    expect($response->json('source_mode'))->toBe('text')
        ->and($response->json('text'))->toContain('Team Offsite on 2026-09-10.')
        ->and($response->json('original_filename'))->toBe('schedule.docx')
        ->and($response->json('project_id'))->toBe($this->project->id);
});

it('404s a user unrelated to the pending import\'s project', function () {
    $outsider = User::factory()->create();
    $pendingImport = createPendingImportWithFile($this->project);

    $this->actingAs($outsider)
        ->getJson(route('import.pending.show', $pendingImport))
        ->assertNotFound();
});

it('requires authentication', function () {
    $pendingImport = createPendingImportWithFile($this->project);

    $this->getJson(route('import.pending.show', $pendingImport))->assertUnauthorized();
});

// ── destroy() ────────────────────────────────────────────────────────────────

it('deletes a pending import for a manageable project', function () {
    $pendingImport = createPendingImportWithFile($this->project);

    $this->actingAs($this->admin)
        ->deleteJson(route('import.pending.destroy', $pendingImport))
        ->assertOk();

    expect(PendingImport::find($pendingImport->id))->toBeNull();
});

it('404s a user unrelated to the project deleting a pending import', function () {
    $outsider = User::factory()->create();
    $pendingImport = createPendingImportWithFile($this->project);

    $this->actingAs($outsider)
        ->deleteJson(route('import.pending.destroy', $pendingImport))
        ->assertNotFound();

    expect(PendingImport::find($pendingImport->id))->not->toBeNull();
});
