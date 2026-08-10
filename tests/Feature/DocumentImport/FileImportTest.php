<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Generates a real, minimal .docx file (not a committed binary fixture) so the upload
 * pipeline exercises PhpWord's actual reader against actual Word2007 XML.
 */
function fakeDocxFile(string $name = 'notes.docx'): UploadedFile
{
    $phpWord = new \PhpOffice\PhpWord\PhpWord;
    $section = $phpWord->addSection();
    $section->addText('Hello from a real docx file.');

    $tmpPath = tempnam(sys_get_temp_dir(), 'docx').'.docx';
    (new \PhpOffice\PhpWord\Writer\Word2007($phpWord))->save($tmpPath);

    return new UploadedFile($tmpPath, $name, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
}

beforeEach(function () {
    setPermissionsTeamId(null);

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

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

it('imports an uploaded docx file as an intake document', function () {
    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => fakeDocxFile('Weekly Notes.docx'),
        ])
        ->assertRedirect();

    $document = $this->project->documents()->where('type', config('workflow.intake_key'))->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Weekly Notes')
        ->and($document->content)->toContain('Hello from a real docx file.')
        ->and($document->content)->not->toContain('<html')
        ->and($document->metadata['import_source'])->toBe('file_upload')
        ->and($document->metadata['original_filename'])->toBe('Weekly Notes.docx');
});

it('imports an uploaded txt file as an intake document', function () {
    $file = UploadedFile::fake()->createWithContent('Raw Transcript.txt', "First line.\nSecond line.");

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => $file,
        ])
        ->assertRedirect();

    $document = $this->project->documents()->where('type', config('workflow.intake_key'))->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Raw Transcript')
        ->and($document->content)->toContain('First line.')
        ->and($document->content)->toContain('Second line.');
});

it('escapes html special characters in an uploaded txt file', function () {
    $file = UploadedFile::fake()->createWithContent('script.txt', '<script>alert(1)</script>');

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), ['file' => $file])
        ->assertRedirect();

    $document = $this->project->documents()->where('type', config('workflow.intake_key'))->first();

    expect($document->content)->not->toContain('<script>')
        ->and($document->content)->toContain('&lt;script&gt;');
});

it('rejects a legacy .doc file upload', function () {
    $file = UploadedFile::fake()->create('legacy.doc', 10);

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), ['file' => $file])
        ->assertSessionHasErrors('file');

    expect(Document::count())->toBe(0);
});

it('rejects a file over the size limit', function () {
    $file = UploadedFile::fake()->create('huge.txt', 10241);

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), ['file' => $file])
        ->assertSessionHasErrors('file');

    expect(Document::count())->toBe(0);
});

it('respects a custom_prompt on file-based imports', function () {
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Some text.');

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => $file,
            'custom_prompt' => 'Extract only decisions.',
        ])
        ->assertRedirect();

    $document = $this->project->documents()->where('type', config('workflow.intake_key'))->first();
    expect($document->custom_prompt)->toBe('Extract only decisions.');
});

it('forbids uploading for a user without transcript-management rights', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);

    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Some text.');

    $this->actingAs($member)
        ->post(route('projects.transcripts.import-file', $this->project), ['file' => $file])
        ->assertForbidden();

    expect(Document::count())->toBe(0);
});

it('does not persist the uploaded file to any disk', function () {
    Storage::fake('public');
    Storage::fake('local');

    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Some text.');

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), ['file' => $file])
        ->assertRedirect();

    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});
