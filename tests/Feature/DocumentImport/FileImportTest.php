<?php

use App\Jobs\ProcessDocumentAI;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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
            'type' => config('workflow.intake_key'),
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
            'type' => config('workflow.intake_key'),
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
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => $file,
            'type' => config('workflow.intake_key'),
        ])
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
            'type' => config('workflow.intake_key'),
        ])
        ->assertRedirect();

    $document = $this->project->documents()->where('type', config('workflow.intake_key'))->first();
    expect($document->custom_prompt)->toBe('Extract only decisions.');
});

it('redirects to a pre-created blank Meeting Notes document, same as a picked recording, when imported as Transcription', function () {
    Queue::fake();

    $template = \App\Models\AiTemplate::create([
        'name' => 'Transcript to Meeting Notes',
        'type' => 'workflow',
        'system_prompt' => 'x',
        'user_prompt' => 'y',
        'single_output' => true,
    ]);
    config(['workflow.intake_to_action_items_ai_template_id' => $template->id]);

    $file = UploadedFile::fake()->createWithContent('Weekly Notes.txt', 'Some transcript text.');

    $response = $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => $file,
            'type' => config('workflow.intake_key'),
        ]);

    $intake = $this->project->documents()->where('type', config('workflow.intake_key'))->firstOrFail();
    $meetingNotes = $this->project->documents()->where('parent_id', $intake->id)->firstOrFail();

    expect($meetingNotes->type)->toBe(config('workflow.action_items_key'))
        ->and($meetingNotes->name)->toBe('Weekly Notes')
        ->and($meetingNotes->content)->toBe('');

    $response->assertRedirect(route('projects.documents.show', [$this->project, $meetingNotes]));
});

it('creates the document as the picked type and skips AI processing when it is not the intake type', function () {
    Queue::fake();

    // resolveDocumentType() validates a picked (non-new, non-intake) type against types
    // actually already in use in this project, not a separate catalog — so there needs to be
    // one already for this to be a valid choice.
    $this->project->documents()->create([
        'type' => config('workflow.action_items_key'),
        'name' => 'Existing Meeting Notes',
        'content' => 'Pre-existing.',
        'processed_at' => now(),
    ]);

    $file = UploadedFile::fake()->createWithContent('Finished Notes.txt', 'Already-finished notes.');

    $response = $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => $file,
            'custom_prompt' => 'This should be ignored.',
            'type' => config('workflow.action_items_key'),
        ]);

    $document = $this->project->documents()->where('name', 'Finished Notes')->first();

    expect($document)->not->toBeNull()
        ->and($document->name)->toBe('Finished Notes')
        ->and($document->content)->toContain('Already-finished notes.')
        ->and($document->custom_prompt)->toBeNull()
        ->and($document->processed_at)->not->toBeNull();

    // Sent straight to the new document's own page, same as the intake branch — not back to
    // the tab it was imported from.
    $response->assertRedirect(route('projects.documents.show', [$this->project, $document]));

    Queue::assertNotPushed(ProcessDocumentAI::class);
});

it('creates a new org-scoped document type and uses it when new_type_label is given', function () {
    Queue::fake();

    $file = UploadedFile::fake()->createWithContent('Design Brief.txt', 'Design brief content.');

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => $file,
            'new_type_label' => 'Design Brief',
        ])
        ->assertRedirect();

    $definition = \App\Models\DocumentTypeDefinition::where('organization_id', $this->org->id)
        ->where('key', 'design_brief')
        ->first();

    expect($definition)->not->toBeNull()
        ->and($definition->label)->toBe('Design Brief')
        ->and($definition->is_task)->toBeFalse();

    $document = $this->project->documents()->where('type', 'design_brief')->first();

    expect($document)->not->toBeNull()
        ->and($document->processed_at)->not->toBeNull();

    Queue::assertNotPushed(ProcessDocumentAI::class);
});

it('reuses an existing document type definition when new_type_label matches one already created', function () {
    $existing = \App\Models\DocumentTypeDefinition::create([
        'organization_id' => $this->org->id,
        'key' => 'design_brief',
        'label' => 'Design Brief',
        'is_task' => false,
        'order' => 1,
    ]);

    $file = UploadedFile::fake()->createWithContent('Another Brief.txt', 'More content.');

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => $file,
            'new_type_label' => 'Design Brief',
        ])
        ->assertRedirect();

    expect(\App\Models\DocumentTypeDefinition::where('organization_id', $this->org->id)->where('key', 'design_brief')->count())->toBe(1);

    $document = $this->project->documents()->where('type', 'design_brief')->first();
    expect($document)->not->toBeNull();
});

it('rejects a type that is not in the organization\'s document type catalog', function () {
    $file = UploadedFile::fake()->createWithContent('notes.txt', 'Some text.');

    $this->actingAs($this->admin)
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => $file,
            'type' => 'not-a-real-type',
        ])
        ->assertStatus(422);

    expect(Document::count())->toBe(0);
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
        ->post(route('projects.transcripts.import-file', $this->project), [
            'file' => $file,
            'type' => config('workflow.intake_key'),
        ])
        ->assertRedirect();

    expect(Storage::disk('public')->allFiles())->toBeEmpty()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});
