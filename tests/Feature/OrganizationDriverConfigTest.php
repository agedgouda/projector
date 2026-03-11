<?php

use App\Models\Organization;

it('applies org llm driver to config', function () {
    $org = Organization::factory()->create([
        'llm_driver' => 'openai',
        'llm_config' => ['key' => 'org-openai-key', 'model' => 'gpt-4o'],
    ]);

    $org->applyDriverConfig();

    expect(config('services.llm_driver'))->toBe('openai')
        ->and(config('services.openai.key'))->toBe('org-openai-key')
        ->and(config('services.openai.model'))->toBe('gpt-4o');
});

it('applies org vector driver to config', function () {
    $org = Organization::factory()->create([
        'vector_driver' => 'openai',
        'vector_config' => ['key' => 'org-vector-key', 'model' => 'text-embedding-3-large'],
    ]);

    $org->applyDriverConfig();

    expect(config('services.vector_driver'))->toBe('openai')
        ->and(config('services.openai.key'))->toBe('org-vector-key')
        ->and(config('services.openai.model'))->toBe('text-embedding-3-large');
});

it('sets vector driver to same without overriding driver-specific config', function () {
    config(['services.gemini.key' => 'system-gemini-key']);

    $org = Organization::factory()->create([
        'llm_driver' => 'gemini',
        'llm_config' => ['key' => 'org-gemini-key'],
        'vector_driver' => 'same',
    ]);

    $org->applyDriverConfig();

    expect(config('services.llm_driver'))->toBe('gemini')
        ->and(config('services.vector_driver'))->toBe('same')
        ->and(config('services.gemini.key'))->toBe('org-gemini-key');
});

it('does not override config when org has no driver set', function () {
    config(['services.llm_driver' => 'gemini']);

    $org = Organization::factory()->create([
        'llm_driver' => null,
    ]);

    $org->applyDriverConfig();

    expect(config('services.llm_driver'))->toBe('gemini');
});

it('does not override config values when org config field is empty', function () {
    config(['services.openai.model' => 'gpt-4o-mini']);

    $org = Organization::factory()->create([
        'llm_driver' => 'openai',
        'llm_config' => ['model' => ''],
    ]);

    $org->applyDriverConfig();

    expect(config('services.llm_driver'))->toBe('openai')
        ->and(config('services.openai.model'))->toBe('gpt-4o-mini');
});

it('applies ollama host and model to config', function () {
    $org = Organization::factory()->create([
        'llm_driver' => 'ollama',
        'llm_config' => ['host' => 'http://my-ollama:11434', 'model' => 'llama3'],
    ]);

    $org->applyDriverConfig();

    expect(config('services.llm_driver'))->toBe('ollama')
        ->and(config('services.ollama.host'))->toBe('http://my-ollama:11434')
        ->and(config('services.ollama.model'))->toBe('llama3');
});
