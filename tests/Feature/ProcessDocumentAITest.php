<?php

use App\Jobs\ProcessDocumentAI;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectType;
use App\Services\Ai\ProjectAiService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createReprocessableDocument(): Document
{
    $org = Organization::create(['name' => 'Acme Inc']);
    $client = Client::create([
        'organization_id' => $org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);
    $projectType = ProjectType::factory()->create();
    $project = Project::create([
        'name' => 'Test Project',
        'client_id' => $client->id,
        'project_type_id' => $projectType->id,
    ]);

    return Document::create([
        'project_id' => $project->id,
        'name' => 'Source Document',
        'type' => 'intake',
        'content' => 'Source content',
        'processed_at' => now(),
    ]);
}

it('deletes all previously generated children before creating new ones, even when the output type has changed', function () {
    $document = createReprocessableDocument();

    $oldChild = Document::create([
        'project_id' => $document->project_id,
        'parent_id' => $document->id,
        'name' => 'Old Child',
        'type' => 'old_output_type',
        'content' => 'Old content',
    ]);

    Document::create([
        'project_id' => $document->project_id,
        'parent_id' => $oldChild->id,
        'name' => 'Old Grandchild',
        'type' => 'old_output_type_detail',
        'content' => 'Old grandchild content',
    ]);

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'output_type' => 'new_output_type',
            'single_output' => false,
            'mock_response' => [
                [
                    'title' => 'New Deliverable',
                    'new_output_type' => 'New content',
                ],
            ],
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    expect(Document::where('type', 'old_output_type')->exists())->toBeFalse();
    expect(Document::where('type', 'old_output_type_detail')->exists())->toBeFalse();

    $newChildren = Document::where('parent_id', $document->id)->get();
    expect($newChildren)->toHaveCount(1);
    expect($newChildren->first())
        ->type->toBe('new_output_type')
        ->name->toBe('New Deliverable');

    expect($document->refresh()->processed_at)->not->toBeNull();
});
