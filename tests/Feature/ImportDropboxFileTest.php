<?php

use App\Jobs\ImportDropboxFile;
use App\Mail\ImportResultMail;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentTypeDefinition;
use App\Models\DropboxWorkspace;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectImportMapping;
use App\Models\User;
use App\Services\Dropbox\DropboxApiClient;
use Illuminate\Support\Facades\Mail;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'task', 'label' => 'Task', 'is_task' => true, 'order' => 1]);
    DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'event', 'label' => 'Event', 'is_task' => false, 'order' => 2]);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);

    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->workspace = DropboxWorkspace::factory()->create([
        'organization_id' => $this->org->id,
        'installed_by_user_id' => $this->user->id,
    ]);
});

it('imports a known-mapping csv and attributes it to the workspace owner', function () {
    $csv = "Name,Assignee\nFollow up with client,\nSend invoice,";
    $mapping = ['name' => 'Name', 'priority' => null, 'task_status' => null, 'due_at' => null, 'assignee' => 'Assignee', 'start_date' => null, 'description' => null, 'tag' => null];
    ProjectImportMapping::record($this->project, 'task', $mapping);

    $this->mock(\App\Contracts\LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => ['passes' => [
            ['list_type' => 'task', 'mapping' => $mapping, 'rationale' => 'test'],
        ]]]);

    $this->mock(DropboxApiClient::class)
        ->shouldReceive('download')
        ->once()
        ->andReturn($csv);

    ImportDropboxFile::dispatchSync($this->project, $this->workspace, '/intake/tasks.csv', 'tasks.csv');

    $tasks = Document::where('project_id', $this->project->id)->where('type', 'task')->get();
    expect($tasks)->toHaveCount(2)
        ->and(Document::where('project_id', $this->project->id)->where('type', 'task_list_import')->first()->creator_id)->toBe($this->user->id);
});

it('notifies the workspace owner that the import has started, before processing the file', function () {
    // Downloading and classifying can take a while (ImportTaskList runs synchronously inline) —
    // without this, whoever's watching has no way to tell "still working on it" apart from
    // "nothing happened at all", which is exactly the confusion that prompted adding it.
    Mail::fake();

    $csv = "Name,Assignee\nFollow up with client,\nSend invoice,";
    $mapping = ['name' => 'Name', 'priority' => null, 'task_status' => null, 'due_at' => null, 'assignee' => 'Assignee', 'start_date' => null, 'description' => null, 'tag' => null];
    ProjectImportMapping::record($this->project, 'task', $mapping);

    $this->mock(\App\Contracts\LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->andReturn(['status' => 'success', 'content' => ['passes' => [
            ['list_type' => 'task', 'mapping' => $mapping, 'rationale' => 'test'],
        ]]]);

    $this->mock(DropboxApiClient::class)
        ->shouldReceive('download')
        ->once()
        ->andReturn($csv);

    ImportDropboxFile::dispatchSync($this->project, $this->workspace, '/intake/tasks.csv', 'tasks.csv');

    Mail::assertSent(ImportResultMail::class, fn ($mail) => str_contains($mail->resultMessage, 'Importing "tasks.csv" from Dropbox'));
    Mail::assertSent(ImportResultMail::class, fn ($mail) => str_contains($mail->resultMessage, 'Imported 2 task(s)'));
});

it('does not send a started notification when there is no one to attribute the import to', function () {
    Mail::fake();

    $this->workspace->update(['installed_by_user_id' => null]);

    ImportDropboxFile::dispatchSync($this->project, $this->workspace->fresh(), '/intake/tasks.csv', 'tasks.csv');

    Mail::assertNothingSent();
});

it('does nothing when the workspace has no installed_by_user_id to attribute to', function () {
    $this->workspace->update(['installed_by_user_id' => null]);

    ImportDropboxFile::dispatchSync($this->project, $this->workspace->fresh(), '/intake/tasks.csv', 'tasks.csv');

    expect(Document::where('project_id', $this->project->id)->count())->toBe(0);
});

it('ignores a file whose extension is not importable', function () {
    $this->mock(DropboxApiClient::class)->shouldNotReceive('download');

    ImportDropboxFile::dispatchSync($this->project, $this->workspace, '/intake/photo.png', 'photo.png');

    expect(Document::where('project_id', $this->project->id)->count())->toBe(0);
});

it('files a docx directly as the tagged type via a subfolder name, with no #tag needed', function () {
    DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'meeting_notes', 'label' => 'Meeting Notes', 'is_task' => false, 'order' => 3]);

    $phpWord = new \PhpOffice\PhpWord\PhpWord;
    $phpWord->addSection()->addText('Discussed the roadmap.');
    $tmpPath = tempnam(sys_get_temp_dir(), 'dropbox_test_docx').'.docx';
    \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tmpPath);
    $bytes = (string) file_get_contents($tmpPath);
    @unlink($tmpPath);

    $this->mock(DropboxApiClient::class)
        ->shouldReceive('download')
        ->once()
        ->andReturn($bytes);

    ImportDropboxFile::dispatchSync($this->project, $this->workspace, '/intake/meeting-notes/standup.docx', 'standup.docx', 'meeting-notes');

    $document = Document::where('project_id', $this->project->id)->where('type', 'meeting_notes')->first();
    expect($document)->not->toBeNull()
        ->and($document->content)->toBe('Discussed the roadmap.')
        ->and($document->creator_id)->toBe($this->user->id);
});
