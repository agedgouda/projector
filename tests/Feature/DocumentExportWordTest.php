<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super-admin');

    // Seeds the global document type catalog (via ProjectTypeObserver) with the type this
    // test exercises - unrelated to any specific project's protocol, since projects no longer
    // have one.
    ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Action Items', 'key' => 'action_items', 'is_task' => false],
        ],
    ]);

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

    setPermissionsTeamId($this->org->id);
});

it('downloads a word document for an authorized user', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items for Kickoff',
        'type' => 'action_items',
        'content' => '<p>Follow up with the client.</p>',
        'processed_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.documents.exportWord', [$this->project, $document]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($response->headers->get('content-disposition'))->toContain('action-items-for-kickoff.docx');
});

it('downloads a word document when the content has unclosed html tags', function () {
    // PhpWord's HTML importer parses input as strict XML; the app's rich-text editor can
    // produce ordinary (non-self-closing) HTML like a bare <br>, which must be normalized
    // first or DOMDocument::loadXML() throws a tag-mismatch error.
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items for Kickoff',
        'type' => 'action_items',
        'content' => '<p>Line one<br>Line two</p><p>Follow up with <strong>the client<br></p>',
        'processed_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.documents.exportWord', [$this->project, $document]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
});

it('downloads a valid word document when the name or content contains an unescaped ampersand', function () {
    // Raw "&" is invalid inside XML text content unless escaped; PhpWord's Settings
    // defaults output escaping to *off*, so without AppServiceProvider explicitly
    // enabling it, this produces a document.xml Word refuses to open.
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Draft approved Q&A responses',
        'type' => 'action_items',
        'content' => '<p>Covers R&D and Sales & Marketing.</p>',
        'processed_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('projects.documents.exportWord', [$this->project, $document]));

    $response->assertOk();

    $tmpFile = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($tmpFile, $response->streamedContent());

    // Throws if word/document.xml isn't well-formed XML - proves the file is valid,
    // not just that a response was returned.
    $loaded = \PhpOffice\PhpWord\IOFactory::load($tmpFile);
    unlink($tmpFile);

    expect($loaded)->toBeInstanceOf(\PhpOffice\PhpWord\PhpWord::class);
});

it('denies access to a document outside the user\'s organization', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Private Notes',
        'type' => 'action_items',
        'content' => 'Secret content',
        'processed_at' => now(),
    ]);

    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(route('projects.documents.exportWord', [$this->project, $document]))
        ->assertNotFound();
});

it('returns 404 when the document does not belong to the given project', function () {
    $otherProject = Project::create([
        'name' => 'Other Project',
        'client_id' => $this->client->id,
    ]);

    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Some content',
        'processed_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get(route('projects.documents.exportWord', [$otherProject, $document]))
        ->assertNotFound();
});

it('redirects guests to login', function () {
    $document = Document::create([
        'project_id' => $this->project->id,
        'name' => 'Action Items',
        'type' => 'action_items',
        'content' => 'Some content',
        'processed_at' => now(),
    ]);

    $this->get(route('projects.documents.exportWord', [$this->project, $document]))
        ->assertRedirect();
});
