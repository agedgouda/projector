<?php

use App\Models\Client;
use App\Models\DropboxFolderBinding;
use App\Models\DropboxWorkspace;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    setPermissionsTeamId(null);

    $this->org = Organization::create(['name' => 'Test Org']);
    $this->user = User::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);
    setPermissionsTeamId($this->org->id);

    $this->workspace = DropboxWorkspace::factory()->create(['organization_id' => $this->org->id]);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Test Client',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $this->project = Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);
});

// ── Organization dashboard folder data ──────────────────────────────────────

it('lists existing bindings and unbound available folders on the organization dashboard', function () {
    DropboxFolderBinding::factory()->create([
        'dropbox_workspace_id' => $this->workspace->id,
        'folder_id' => 'id:folder1',
        'folder_path' => '/Client Intake',
        'project_id' => $this->project->id,
    ]);

    Http::fake([
        'api.dropboxapi.com/2/files/list_folder' => Http::response([
            'entries' => [
                ['.tag' => 'folder', 'id' => 'id:folder1', 'name' => 'Client Intake', 'path_display' => '/Client Intake'],
                ['.tag' => 'folder', 'id' => 'id:folder2', 'name' => 'Projector', 'path_display' => '/Projector'],
            ],
            'cursor' => 'cursor-abc',
            'has_more' => false,
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('organizations.index', ['org' => $this->org->id]))
        ->assertInertia(fn ($page) => $page
            ->has('dropboxBindings', 1)
            ->where('dropboxBindings.0.folder_path', '/Client Intake')
            ->where('dropboxBindings.0.project.name', 'Test Project')
            ->has('dropboxAvailableFolders', 1)
            ->where('dropboxAvailableFolders.0.id', 'id:folder2')
        );
});

it('degrades to an empty available-folder list, keeping bindings, when the dropbox api call fails', function () {
    DropboxFolderBinding::factory()->create([
        'dropbox_workspace_id' => $this->workspace->id,
        'folder_id' => 'id:folder1',
        'folder_path' => '/Client Intake',
        'project_id' => $this->project->id,
    ]);

    Http::fake([
        'api.dropboxapi.com/2/files/list_folder' => Http::response('server error', 500),
    ]);

    $this->actingAs($this->user)
        ->get(route('organizations.index', ['org' => $this->org->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('dropboxBindings', 1)
            ->has('dropboxAvailableFolders', 0)
        );
});

// ── Store ───────────────────────────────────────────────────────────────────

it('creates a new binding from a folder picked in the dropdown', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.dropbox.folders.store', $this->org), [
            'folder_id' => 'id:abc123',
            'folder_path' => '/Intake',
            'project_id' => $this->project->id,
        ])
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));

    $binding = DropboxFolderBinding::where('dropbox_workspace_id', $this->workspace->id)->where('folder_id', 'id:abc123')->first();

    expect($binding)->not->toBeNull()
        ->and($binding->folder_path)->toBe('/Intake')
        ->and($binding->project_id)->toBe($this->project->id);
});

it('requires a folder_id, folder_path, and project_id', function () {
    $this->actingAs($this->user)
        ->post(route('organizations.dropbox.folders.store', $this->org), [])
        ->assertSessionHasErrors(['folder_id', 'folder_path', 'project_id']);

    expect(DropboxFolderBinding::count())->toBe(0);
});

it('repoints an existing binding to a different project instead of erroring', function () {
    $otherProject = Project::create(['name' => 'Other Project', 'client_id' => $this->client->id]);

    DropboxFolderBinding::factory()->create([
        'dropbox_workspace_id' => $this->workspace->id,
        'folder_id' => 'id:abc123',
        'folder_path' => '/Intake',
        'project_id' => $this->project->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('organizations.dropbox.folders.store', $this->org), [
            'folder_id' => 'id:abc123',
            'folder_path' => '/Intake',
            'project_id' => $otherProject->id,
        ])
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));

    expect(DropboxFolderBinding::where('dropbox_workspace_id', $this->workspace->id)->where('folder_id', 'id:abc123')->count())->toBe(1)
        ->and(DropboxFolderBinding::where('folder_id', 'id:abc123')->first()->project_id)->toBe($otherProject->id);
});

it('rejects binding to a project outside the organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $outsideProject = Project::create(['name' => 'Outside Project', 'client_id' => $otherClient->id]);

    $this->actingAs($this->user)
        ->post(route('organizations.dropbox.folders.store', $this->org), [
            'folder_id' => 'id:abc123',
            'folder_path' => '/Intake',
            'project_id' => $outsideProject->id,
        ])
        ->assertNotFound();

    expect(DropboxFolderBinding::where('folder_id', 'id:abc123')->exists())->toBeFalse();
});

it('forbids a non-admin org member from creating a binding', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'contributor']);

    $this->actingAs($member)
        ->post(route('organizations.dropbox.folders.store', $this->org), [
            'folder_id' => 'id:abc123',
            'folder_path' => '/Intake',
            'project_id' => $this->project->id,
        ])
        ->assertNotFound();

    expect(DropboxFolderBinding::where('folder_id', 'id:abc123')->exists())->toBeFalse();
});

it('404s creating a binding when dropbox is not connected', function () {
    $this->workspace->delete();

    $this->actingAs($this->user)
        ->post(route('organizations.dropbox.folders.store', $this->org), [
            'folder_id' => 'id:abc123',
            'folder_path' => '/Intake',
            'project_id' => $this->project->id,
        ])
        ->assertNotFound();
});

// ── Destroy ─────────────────────────────────────────────────────────────────

it('deletes a binding', function () {
    $binding = DropboxFolderBinding::factory()->create([
        'dropbox_workspace_id' => $this->workspace->id,
        'project_id' => $this->project->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('organizations.dropbox.folders.destroy', [$this->org, $binding]))
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id, 'tab' => 'configuration']));

    expect(DropboxFolderBinding::find($binding->id))->toBeNull();
});

it('404s deleting a binding that belongs to a different organization', function () {
    $otherOrg = Organization::create(['name' => 'Other Org']);
    $otherWorkspace = DropboxWorkspace::factory()->create(['organization_id' => $otherOrg->id]);
    $otherClient = Client::create([
        'organization_id' => $otherOrg->id,
        'company_name' => 'Other Client',
        'contact_name' => 'John Doe',
        'contact_phone' => '555-5678',
    ]);
    $otherProject = Project::create(['name' => 'Other Project', 'client_id' => $otherClient->id]);

    $binding = DropboxFolderBinding::factory()->create([
        'dropbox_workspace_id' => $otherWorkspace->id,
        'project_id' => $otherProject->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('organizations.dropbox.folders.destroy', [$this->org, $binding]))
        ->assertNotFound();

    expect(DropboxFolderBinding::find($binding->id))->not->toBeNull();
});
