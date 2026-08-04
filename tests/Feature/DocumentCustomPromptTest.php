<?php

use App\Contracts\LlmDriver;
use App\Jobs\ProcessDocumentAI;
use App\Models\AiTemplate;
use App\Models\Client;
use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\ProjectAiService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme Inc']);
    $this->admin = User::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'org-admin']);

    $this->client = Client::create([
        'organization_id' => $this->org->id,
        'company_name' => 'Client Co',
        'contact_name' => 'Jane Doe',
        'contact_phone' => '555-1234',
    ]);

    $this->project = Project::create([
        'name' => 'Test Project',
        'client_id' => $this->client->id,
    ]);
});

function createDocumentWithCustomPrompt(Project $project, string $prompt): Document
{
    // processed_at is set so DocumentObserver::created() doesn't auto-dispatch the real
    // (unmocked) AI pipeline synchronously (QUEUE_CONNECTION=sync in tests) — the same
    // guard createReprocessableDocument() uses in ProcessDocumentAITest.php.
    return Document::create([
        'project_id' => $project->id,
        'name' => 'Meeting Notes',
        'type' => 'intake',
        'content' => 'Raw meeting transcript...',
        'priority' => 'low',
        'task_status' => 'todo',
        'processed_at' => now(),
        'custom_prompt' => $prompt,
    ]);
}

it('persists custom_prompt when creating a document', function () {
    // A non-intake type so DocumentObserver::created() doesn't auto-dispatch the real AI
    // pipeline synchronously for this plain persistence check.
    $this->actingAs($this->admin)
        ->post(route('projects.documents.store', $this->project), [
            'name' => 'Meeting Notes',
            'type' => 'task',
            'content' => 'Raw transcript',
            'priority' => 'low',
            'task_status' => 'todo',
            'custom_prompt' => 'Clean this up into full meeting notes.',
        ])
        ->assertRedirect();

    $document = Document::where('name', 'Meeting Notes')->firstOrFail();
    expect($document->custom_prompt)->toBe('Clean this up into full meeting notes.');
});

it('persists custom_prompt when updating a document', function () {
    $document = createDocumentWithCustomPrompt($this->project, '');

    $this->actingAs($this->admin)
        ->put(route('projects.documents.update', [$this->project, $document]), [
            'custom_prompt' => 'Summarize for marketing only, no personal details.',
        ])
        ->assertRedirect();

    expect($document->fresh()->custom_prompt)->toBe('Summarize for marketing only, no personal details.');
});

it('sends a document\'s own custom_prompt and its content together as one free-form message, unconstrained by any JSON schema', function () {
    $document = createDocumentWithCustomPrompt(
        $this->project,
        'This is a meeting about marketing a new product. Clean up and create full meeting notes, eliminating anything personal.'
    );

    $this->mock(LlmDriver::class)
        ->shouldReceive('completeFreeform')
        ->once()
        ->withArgs(fn (string $systemPrompt, string $userMessage) => ! str_contains($systemPrompt, 'eliminating anything personal')
            && str_contains($userMessage, 'eliminating anything personal')
            && str_contains($userMessage, 'Raw meeting transcript'))
        ->andReturn([
            'status' => 'success',
            'content' => "Marketing Briefing\n\nCleaned meeting notes.",
        ]);

    $result = app(ProjectAiService::class)->process($document);

    expect($result['output_type'])->toBe('action_items');
    expect($result['single_output'])->toBeTrue();
    expect($result['mock_response']['title'])->toBe('Marketing Briefing');
    expect($result['mock_response']['content'])->toBe('Cleaned meeting notes.');
});

it('creates a new child document from a custom prompt and leaves the source document untouched, the same way the default pipeline does', function () {
    $document = createDocumentWithCustomPrompt($this->project, 'Clean up these notes.');
    $originalContent = $document->content;

    $this->mock(ProjectAiService::class, function ($mock) {
        $mock->shouldReceive('process')->once()->andReturn([
            'status' => 'success',
            'mock_response' => ['title' => 'Marketing Briefing', 'content' => '# Cleaned Notes'],
            'output_type' => 'action_items',
            'single_output' => true,
            'locked_project_type_id' => null,
        ]);
    });

    (new ProcessDocumentAI($document))->handle();

    $document->refresh();
    expect($document->content)->toBe($originalContent);
    expect($document->processed_at)->not->toBeNull();

    $child = Document::where('parent_id', $document->id)->firstOrFail();
    expect($child->type)->toBe('action_items');
    expect($child->name)->toBe('Marketing Briefing');
    expect($child->content)->toContain('Cleaned Notes');
});

it('lets an explicit override step win over a document\'s stored custom_prompt', function () {
    $document = createDocumentWithCustomPrompt($this->project, 'Clean up these notes.');

    $template = AiTemplate::create([
        'name' => 'Notes to Action items',
        'type' => 'workflow',
        'system_prompt' => 'Extract action items.',
        'user_prompt' => '{{input}}',
    ]);

    $this->mock(LlmDriver::class)
        ->shouldReceive('call')
        ->once()
        ->withArgs(fn (string $systemPrompt) => str_contains($systemPrompt, 'Extract action items'))
        ->andReturn([
            'status' => 'success',
            'content' => [
                ['title' => 'Item', 'action_items' => 'Follow up', 'criteria' => []],
            ],
        ]);

    $result = app(ProjectAiService::class)->process($document, [
        'to_key' => 'action_items',
        'ai_template_id' => $template->id,
    ]);

    expect($result['output_type'])->toBe('action_items');
});
