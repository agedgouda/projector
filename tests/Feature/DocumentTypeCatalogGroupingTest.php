<?php

use App\Models\Client;
use App\Models\DocumentTypeDefinition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createProjectForCatalogGrouping(): Project
{
    $org = Organization::create(['name' => 'Acme Inc']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $projectType = ProjectType::factory()->create([
        'document_schema' => [
            ['label' => 'Notes', 'key' => 'intake', 'is_task' => false],
            ['label' => 'Action Items', 'key' => 'action_items', 'is_task' => true],
        ],
    ]);

    return Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
        'project_type_id' => $projectType->id,
    ]);
}

it('shows a document produced by a different protocol\'s recipe in the Kanban pipe, using the global catalog label', function () {
    $project = createProjectForCatalogGrouping();

    // "user_story" is never in this project's own protocol schema — only in the global catalog,
    // as if it were produced by running the document through a different protocol's recipe.
    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'user_story',
        'label' => 'User Story',
        'is_task' => true,
        'order' => 1,
    ]);

    $document = $project->documents()->create([
        'name' => 'Cross-protocol document',
        'type' => 'user_story',
        'content' => 'As a user...',
    ]);

    $kanbanDocuments = $project->getKanbanDocuments();

    expect($kanbanDocuments->pluck('id'))->toContain($document->id);
    expect($kanbanDocuments->firstWhere('id', $document->id)['type_label'])->toBe('User Story');
});

it('excludes a cross-protocol task-type document from the documentation pipe', function () {
    $project = createProjectForCatalogGrouping();

    DocumentTypeDefinition::create([
        'organization_id' => null,
        'key' => 'user_story',
        'label' => 'User Story',
        'is_task' => true,
        'order' => 1,
    ]);

    $document = $project->documents()->create([
        'name' => 'Cross-protocol document',
        'type' => 'user_story',
        'content' => 'As a user...',
    ]);

    expect($project->getDocumentationPipe()->pluck('id'))->not->toContain($document->id);
    expect($project->getKanbanPipe()->get('user_story')?->pluck('id'))->toContain($document->id);
});

it('treats an uncataloged document type as documentation by default', function () {
    $project = createProjectForCatalogGrouping();

    $document = $project->documents()->create([
        'name' => 'Totally unknown type',
        'type' => 'mystery_type',
        'content' => 'content',
    ]);

    expect($project->getDocumentationPipe()->pluck('id'))->toContain($document->id);
    expect($project->getKanbanPipe()->has('mystery_type'))->toBeFalse();
});
