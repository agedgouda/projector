<?php

use App\Jobs\GenerateOrgDocumentEmbedding;
use App\Jobs\ProcessOrgDocumentAI;
use App\Models\Organization;
use App\Models\OrgDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/*
 * A queue worker (Horizon) runs many jobs in one long-lived process, resetting scoped container
 * instances between them but never config — so whatever one organization's applyDriverConfig()
 * wrote must not carry over into a later job for another organization.
 */

beforeEach(function () {
    Queue::fake([GenerateOrgDocumentEmbedding::class, ProcessOrgDocumentAI::class]);

    config([
        'services.llm_driver' => 'openai',
        'services.vector_driver' => 'openai',
        'services.openai.key' => 'system-openai-key',
        'services.openai.model' => 'gpt-system',
    ]);

    Http::fake([
        'api.openai.com/v1/embeddings' => Http::response(['data' => [['embedding' => array_fill(0, 1536, 0.01)]]]),
    ]);
});

function embedInSameProcess(Organization $organization): void
{
    $orgDocument = OrgDocument::create([
        'organization_id' => $organization->id,
        'name' => 'Weekly Sync',
        'type' => 'status_meeting',
        'content' => 'Transcript for '.$organization->name,
    ]);

    // What the worker does between jobs: forget scoped instances, keep config.
    app()->forgetScopedInstances();

    (new GenerateOrgDocumentEmbedding($orgDocument))->handle();
}

it('does not send a default org\'s content with another org\'s own key', function () {
    $orgWithOwnKey = Organization::factory()->create([
        'name' => 'Own Key Org',
        'llm_driver' => 'openai',
        'llm_config' => ['key' => 'org-a-openai-key', 'model' => 'gpt-org-a'],
    ]);
    $defaultOrg = Organization::factory()->create(['name' => 'Default Org']);

    embedInSameProcess($orgWithOwnKey);
    embedInSameProcess($defaultOrg);

    $tokens = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Authorization')[0] ?? null)->all();

    expect($tokens)->toBe(['Bearer org-a-openai-key', 'Bearer system-openai-key']);
});

it('restores the system default driver after an org that overrode it', function () {
    $ollamaOrg = Organization::factory()->create([
        'llm_driver' => 'ollama',
        'llm_config' => ['host' => 'http://org-ollama:11434', 'model' => 'llama3'],
        'vector_driver' => 'same',
    ]);
    $defaultOrg = Organization::factory()->create();

    $ollamaOrg->applyDriverConfig();
    $defaultOrg->applyDriverConfig();

    expect(config('services.llm_driver'))->toBe('openai')
        ->and(config('services.vector_driver'))->toBe('openai')
        ->and(config('services.openai.key'))->toBe('system-openai-key')
        ->and(config('services.openai.model'))->toBe('gpt-system')
        ->and(config('services.ollama.host'))->not->toBe('http://org-ollama:11434');
});

it('does not carry one org\'s own gemini key to another org that picks gemini', function () {
    $orgA = Organization::factory()->create([
        'llm_driver' => 'gemini',
        'llm_config' => ['key' => 'org-a-gemini-key', 'model' => 'gemini-2.0-flash'],
    ]);
    $orgB = Organization::factory()->create(['llm_driver' => 'openai', 'llm_config' => ['model' => 'gpt-4o']]);

    $orgA->applyDriverConfig();
    $orgB->applyDriverConfig();

    expect(config('services.gemini.key'))->toBeNull();
});

it('restores the system defaults before a job that never applies an organization', function () {
    Organization::factory()->create([
        'llm_driver' => 'openai',
        'llm_config' => ['key' => 'org-a-openai-key', 'model' => 'gpt-org-a'],
    ])->applyDriverConfig();

    expect(config('services.openai.key'))->toBe('org-a-openai-key');

    event(new \Illuminate\Queue\Events\JobProcessing('sync', new \Illuminate\Queue\Jobs\SyncJob(app(), json_encode(['job' => 'probe', 'data' => []]), 'sync', 'default')));

    expect(config('services.openai.key'))->toBe('system-openai-key')
        ->and(config('services.openai.model'))->toBe('gpt-system');
});

it('leaves config alone before a job when no organization has overridden it', function () {
    config(['services.openai.model' => 'set-by-something-else']);

    event(new \Illuminate\Queue\Events\JobProcessing('sync', new \Illuminate\Queue\Jobs\SyncJob(app(), json_encode(['job' => 'probe', 'data' => []]), 'sync', 'default')));

    expect(config('services.openai.model'))->toBe('set-by-something-else');
});

describe('jobs that act for an organization use its own AI provider', function () {
    beforeEach(function () {
        \App\Models\DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'task', 'label' => 'Task', 'is_task' => true, 'order' => 1]);
        \App\Models\DocumentTypeDefinition::create(['organization_id' => null, 'key' => 'event', 'label' => 'Event', 'is_task' => false, 'order' => 2]);

        $this->org = Organization::factory()->create([
            'llm_driver' => 'ollama',
            'llm_config' => ['host' => 'http://org-ollama:11434', 'model' => 'llama3'],
            'vector_driver' => 'same',
        ]);
        $this->client = \App\Models\Client::create([
            'organization_id' => $this->org->id,
            'company_name' => 'Test Client',
            'contact_name' => 'Jane Doe',
            'contact_phone' => '555-1234',
        ]);
        $this->project = \App\Models\Project::create(['name' => 'Test Project', 'client_id' => $this->client->id]);
        $this->user = \App\Models\User::factory()->create();
        $this->org->users()->attach($this->user->id, ['role' => 'org-admin']);

        // Every outbound call fails fast; only where it was sent matters here.
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 500)]);
    });

    $sentToOrgOllama = fn (): bool => Http::recorded()
        ->contains(fn (array $pair): bool => str_starts_with($pair[0]->url(), 'http://org-ollama:11434/'));

    $sentToOpenAi = fn (): bool => Http::recorded()
        ->contains(fn (array $pair): bool => str_contains($pair[0]->url(), 'api.openai.com'));

    $runIgnoringFailure = function (callable $run): void {
        try {
            $run();
        } catch (\Throwable) {
            // The faked provider errors; some jobs rethrow. Irrelevant to where the call went.
        }
    };

    it('uses it for Slack /task', function () use ($sentToOrgOllama, $sentToOpenAi, $runIgnoringFailure) {
        $runIgnoringFailure(fn () => \App\Jobs\CreateTaskFromSlackCommand::dispatchSync($this->project, $this->user, 'Draft the brief', 'https://hooks.slack.com/commands/fake'));

        expect($sentToOrgOllama())->toBeTrue()->and($sentToOpenAi())->toBeFalse();
    });

    it('uses it for Slack /events', function () use ($sentToOrgOllama, $sentToOpenAi, $runIgnoringFailure) {
        $runIgnoringFailure(fn () => \App\Jobs\CreateEventFromSlackCommand::dispatchSync($this->project, $this->user, 'Kickoff next Tuesday', 'https://hooks.slack.com/commands/fake'));

        expect($sentToOrgOllama())->toBeTrue()->and($sentToOpenAi())->toBeFalse();
    });

    it('uses it for Slack /report', function () use ($sentToOrgOllama, $sentToOpenAi, $runIgnoringFailure) {
        \App\Models\AiTemplate::create([
            'name' => 'Slack report request',
            'type' => 'slack_report_request',
            'system_prompt' => 'Parse the request.',
            'user_prompt' => '{{request}}',
        ]);
        $workspace = \App\Models\SlackWorkspace::factory()->create(['organization_id' => $this->org->id]);

        $runIgnoringFailure(fn () => \App\Jobs\GenerateReportFromSlackCommand::dispatchSync($this->project, $this->user, $workspace, 'C123', 'high priority tasks', 'https://hooks.slack.com/commands/fake'));

        expect($sentToOrgOllama())->toBeTrue()->and($sentToOpenAi())->toBeFalse();
    });

    it('uses it for text imports', function () use ($sentToOrgOllama, $sentToOpenAi, $runIgnoringFailure) {
        Queue::fake([GenerateOrgDocumentEmbedding::class, ProcessOrgDocumentAI::class, \App\Jobs\GenerateDocumentEmbedding::class, \App\Jobs\ProcessDocumentAI::class]);

        $import = \App\Models\Document::create([
            'project_id' => $this->project->id,
            'name' => 'Imported list',
            'type' => 'task',
            'content' => 'source text',
        ]);

        $runIgnoringFailure(fn () => \App\Jobs\ExtractTextRecords::dispatchSync($import, 'task', 'source text', 'every bullet is a task'));

        expect($sentToOrgOllama())->toBeTrue()->and($sentToOpenAi())->toBeFalse();
    });

    it('uses it for status meeting extraction', function () use ($sentToOrgOllama, $sentToOpenAi, $runIgnoringFailure) {
        \App\Models\AiTemplate::create([
            'name' => 'Org extraction',
            'type' => 'org_extraction',
            'system_prompt' => 'Extract.',
            'user_prompt' => '{{transcript}}',
        ]);
        $orgDocument = OrgDocument::create([
            'organization_id' => $this->org->id,
            'name' => 'Weekly Sync',
            'type' => 'status_meeting',
            'content' => 'Transcript',
        ]);

        $runIgnoringFailure(fn () => (new ProcessOrgDocumentAI($orgDocument))->handle());

        expect($sentToOrgOllama())->toBeTrue()->and($sentToOpenAi())->toBeFalse();
    });

    it('uses it for status meeting embeddings', function () use ($sentToOrgOllama, $sentToOpenAi, $runIgnoringFailure) {
        $orgDocument = OrgDocument::create([
            'organization_id' => $this->org->id,
            'name' => 'Weekly Sync',
            'type' => 'status_meeting',
            'content' => 'Transcript',
        ]);

        $runIgnoringFailure(fn () => (new GenerateOrgDocumentEmbedding($orgDocument))->handle());

        expect($sentToOrgOllama())->toBeTrue()->and($sentToOpenAi())->toBeFalse();
    });
});
