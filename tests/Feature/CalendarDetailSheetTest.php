<?php

use App\Models\Client;
use App\Models\Document;
use App\Models\Faq;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Spatie\Permission\Models\Role;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);
    Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Parent Project', 'client_id' => $this->client->id]);
    $this->child = Project::create(['name' => 'Sub Project', 'client_id' => $this->client->id, 'parent_id' => $this->project->id]);

    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

    setPermissionsTeamId($this->org->id);

    $this->event = Document::create([
        'project_id' => $this->child->id,
        'name' => 'Launch Party',
        'type' => 'event',
        'content' => 'Details',
        'start_at' => '2026-09-20',
        'due_at' => '2026-09-22',
    ]);
});

it('returns a sub-project event as the board record the detail sheet shows', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.documents.record', [$this->child, $this->event]))
        ->assertOk()
        ->assertJsonPath('id', $this->event->id)
        ->assertJsonPath('type', 'event')
        ->assertJsonPath('name', 'Launch Party')
        ->assertJsonPath('project_id', $this->child->id)
        ->assertJsonStructure(['categories', 'comments', 'start_at', 'due_at', 'type_label']);
});

it('404s when the document belongs to a different project', function () {
    $this->actingAs($this->user)
        ->getJson(route('projects.documents.record', [$this->project, $this->event]))
        ->assertNotFound();
});

it('hides the record from users outside the organization', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('projects.documents.record', [$this->child, $this->event]))
        ->assertNotFound();
});

it('requires authentication', function () {
    $this->getJson(route('projects.documents.record', [$this->child, $this->event]))
        ->assertUnauthorized();
});

it('calls the project tab just "Calendar" in the FAQ', function () {
    expect(Faq::where('question', 'like', '%Campaign Calendar%')->orWhere('answer', 'like', '%Campaign Calendar%')->exists())->toBeFalse()
        ->and(Faq::where('question', 'How do I use the Calendar?')->exists())->toBeTrue()
        ->and(Faq::where('answer', 'like', '%Calendar tab%')->count())->toBeGreaterThan(0)
        ->and(Faq::where('keywords', 'like', '%campaign calendar%')->exists())->toBeTrue();
});
