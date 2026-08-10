<?php

use App\Jobs\ProcessOrgDocumentAI;
use App\Models\Organization;
use App\Models\OrgDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Generates a real, minimal .docx file (not a committed binary fixture) so the upload
 * pipeline exercises PhpWord's actual reader against actual Word2007 XML.
 */
function fakeOrgDocxFile(string $name = 'notes.docx'): UploadedFile
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

    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);
});

it('imports an uploaded docx file as a status meeting and auto-dispatches extraction', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post(route('organizations.import-file', $this->org), [
            'file' => fakeOrgDocxFile('Weekly Notes.docx'),
        ])
        ->assertRedirect();

    $orgDocument = $this->org->orgDocuments()->where('type', 'status_meeting')->first();

    expect($orgDocument)->not->toBeNull()
        ->and($orgDocument->name)->toBe('Weekly Notes')
        ->and($orgDocument->content)->toContain('Hello from a real docx file.')
        ->and($orgDocument->content)->not->toContain('<html')
        ->and($orgDocument->metadata['import_source'])->toBe('file_upload')
        ->and($orgDocument->metadata['original_filename'])->toBe('Weekly Notes.docx');

    Queue::assertPushed(ProcessOrgDocumentAI::class, fn ($job) => $job->orgDocument->is($orgDocument));
});

it('imports an uploaded txt file as a status meeting', function () {
    Queue::fake();

    $file = UploadedFile::fake()->createWithContent('Raw Transcript.txt', "First line.\nSecond line.");

    $this->actingAs($this->admin)
        ->post(route('organizations.import-file', $this->org), [
            'file' => $file,
        ])
        ->assertRedirect();

    $orgDocument = $this->org->orgDocuments()->where('type', 'status_meeting')->first();

    expect($orgDocument)->not->toBeNull()
        ->and($orgDocument->name)->toBe('Raw Transcript')
        ->and($orgDocument->content)->toContain('First line.')
        ->and($orgDocument->content)->toContain('Second line.');
});

it('rejects a legacy .doc file upload', function () {
    $file = UploadedFile::fake()->create('legacy.doc', 10);

    $this->actingAs($this->admin)
        ->post(route('organizations.import-file', $this->org), ['file' => $file])
        ->assertSessionHasErrors('file');

    expect(OrgDocument::count())->toBe(0);
});

it('rejects a file over the size limit', function () {
    $file = UploadedFile::fake()->create('huge.txt', 10241);

    $this->actingAs($this->admin)
        ->post(route('organizations.import-file', $this->org), ['file' => $file])
        ->assertSessionHasErrors('file');

    expect(OrgDocument::count())->toBe(0);
});

it('respects a custom_prompt on file-based imports', function () {
    Queue::fake();

    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Some text.');

    $this->actingAs($this->admin)
        ->post(route('organizations.import-file', $this->org), [
            'file' => $file,
            'custom_prompt' => 'Extract only decisions.',
        ])
        ->assertRedirect();

    $orgDocument = $this->org->orgDocuments()->where('type', 'status_meeting')->first();
    expect($orgDocument->custom_prompt)->toBe('Extract only decisions.');
});

it('forbids uploading for a user without org-admin rights', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);

    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Some text.');

    $this->actingAs($member)
        ->post(route('organizations.import-file', $this->org), ['file' => $file])
        ->assertNotFound();

    expect(OrgDocument::count())->toBe(0);
});

it('does not persist the uploaded file to any disk', function () {
    Queue::fake();
    Storage::fake('public');
    Storage::fake('local');

    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Some text.');

    $this->actingAs($this->admin)
        ->post(route('organizations.import-file', $this->org), ['file' => $file])
        ->assertRedirect();

    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});
