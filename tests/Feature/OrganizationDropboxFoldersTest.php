<?php

use App\Models\Client;
use App\Models\DropboxFolderBinding;
use App\Models\DropboxWorkspace;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Dropbox\DropboxApiClient;

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

// ── Store ───────────────────────────────────────────────────────────────────

function mockDropboxFolderResolution(string $folderId = 'id:abc123', string $folderPath = '/Intake'): void
{
    test()->mock(DropboxApiClient::class)
        ->shouldReceive('resolveFolder')
        ->andReturn(['folder_id' => $folderId, 'folder_path' => $folderPath]);
}

it('creates a new binding, resolving the typed path to a folder id', function () {
    mockDropboxFolderResolution();

    $this->actingAs($this->user)
        ->post(route('organizations.dropbox.folders.store', $this->org), [
            'folder_path' => '/Intake',
            'project_id' => $this->project->id,
        ])
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id]));

    $binding = DropboxFolderBinding::where('dropbox_workspace_id', $this->workspace->id)->where('folder_id', 'id:abc123')->first();

    expect($binding)->not->toBeNull()
        ->and($binding->folder_path)->toBe('/Intake')
        ->and($binding->project_id)->toBe($this->project->id);
});

it('rejects a folder path dropbox cannot resolve', function () {
    $this->mock(DropboxApiClient::class)
        ->shouldReceive('resolveFolder')
        ->andThrow(new RuntimeException('not found'));

    $this->actingAs($this->user)
        ->post(route('organizations.dropbox.folders.store', $this->org), [
            'folder_path' => '/Does Not Exist',
            'project_id' => $this->project->id,
        ])
        ->assertSessionHasErrors('folder_path');

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

    mockDropboxFolderResolution();

    $this->actingAs($this->user)
        ->post(route('organizations.dropbox.folders.store', $this->org), [
            'folder_path' => '/Intake',
            'project_id' => $otherProject->id,
        ])
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id]));

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
            'folder_path' => '/Intake',
            'project_id' => $this->project->id,
        ])
        ->assertNotFound();

    expect(DropboxFolderBinding::where('folder_id', 'id:abc123')->exists())->toBeFalse();
});

// ── Destroy ─────────────────────────────────────────────────────────────────

it('deletes a binding', function () {
    $binding = DropboxFolderBinding::factory()->create([
        'dropbox_workspace_id' => $this->workspace->id,
        'project_id' => $this->project->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('organizations.dropbox.folders.destroy', [$this->org, $binding]))
        ->assertRedirect(route('organizations.index', ['org' => $this->org->id]));

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
