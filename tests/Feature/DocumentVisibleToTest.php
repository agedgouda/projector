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
    Role::firstOrCreate(['name' => 'org-admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'member', 'guard_name' => 'web']);
});

function createDocumentForUser(User $user): Document
{
    $org = Organization::create(['name' => 'Acme Inc']);
    $client = Client::create(['organization_id' => $org->id, 'company_name' => 'Client Co', 'contact_name' => 'Jane Doe', 'contact_phone' => '555-1234']);
    $client->users()->attach($user->id);
    $projectType = ProjectType::create(['name' => 'General', 'document_schema' => []]);
    $project = Project::create(['name' => 'Test Project', 'client_id' => $client->id, 'project_type_id' => $projectType->id]);

    return Document::create([
        'project_id' => $project->id,
        'name' => 'Test Doc',
        'type' => 'note',
        'content' => 'Hello world',
    ]);
}

it('allows super-admin to see all documents', function () {
    $superAdmin = User::factory()->create();
    setPermissionsTeamId(null);
    $superAdmin->assignRole('super-admin');

    $otherUser = User::factory()->create();
    createDocumentForUser($otherUser);

    $results = Document::visibleTo($superAdmin)->get();

    expect($results)->toHaveCount(1);
});

it('allows a user to see documents on their own clients', function () {
    $user = User::factory()->create();
    createDocumentForUser($user);

    $results = Document::visibleTo($user)->get();

    expect($results)->toHaveCount(1);
});

it('hides documents from users not attached to the client', function () {
    $owner = User::factory()->create();
    createDocumentForUser($owner);

    $stranger = User::factory()->create();

    $results = Document::visibleTo($stranger)->get();

    expect($results)->toHaveCount(0);
});
