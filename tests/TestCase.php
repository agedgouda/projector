<?php

namespace Tests;

use App\Contracts\LlmDriver;
use App\Contracts\VectorDriver;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private ?Closure $realVectorDriver = null;

    private ?Closure $realLlmDriver = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeVectorDriver();
        $this->fakeLlmDriver();

        // Saves made by tests are logged like any other (see RecordSaveLogger); keep them out of
        // the real record-saves log, which is for what actually happened.
        config(['logging.channels.record_saves.path' => storage_path('logs/testing-record-saves.log')]);
    }

    /**
     * Creating a document generates its embedding synchronously (QUEUE_CONNECTION=sync), so
     * without this every such test would call the real embedding API — and fail wherever no
     * API key is configured, such as CI.
     */
    private function fakeVectorDriver(): void
    {
        $concrete = $this->app->getBindings()[VectorDriver::class]['concrete'] ?? null;
        $this->realVectorDriver = $concrete instanceof Closure ? $concrete : null;

        $this->app->scoped(VectorDriver::class, fn () => new class implements VectorDriver
        {
            /**
             * @return array<int, float>
             */
            public function getEmbedding(string $text): array
            {
                return array_fill(0, 1536, 0.01);
            }
        });
    }

    /**
     * Intake documents run the automatic Notes -> Action Items step through the LLM as soon as
     * they're created, so without this those tests would call the real provider — and fail
     * wherever no API key is configured, such as CI. Tests that care about the response still
     * mock LlmDriver themselves, which takes precedence over this.
     */
    private function fakeLlmDriver(): void
    {
        $concrete = $this->app->getBindings()[LlmDriver::class]['concrete'] ?? null;
        $this->realLlmDriver = $concrete instanceof Closure ? $concrete : null;

        $this->app->scoped(LlmDriver::class, fn () => new class implements LlmDriver
        {
            /**
             * @param  array<string, mixed>|null  $responseSchema
             * @return array{status: string, content: mixed}
             */
            public function call(string $systemPrompt, string $userPrompt, ?array $responseSchema = null): array
            {
                return ['status' => 'success', 'content' => $responseSchema ? $this->minimalValueFor($responseSchema) : []];
            }

            /**
             * The smallest value satisfying a JSON schema: every required property filled in,
             * arrays empty.
             *
             * @param  array<string, mixed>  $schema
             */
            private function minimalValueFor(array $schema): mixed
            {
                $type = $schema['type'] ?? 'object';
                $type = is_array($type) ? ($type[0] ?? 'object') : $type;

                if ($type === 'object') {
                    $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
                    $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
                    $value = [];

                    foreach ($required as $name) {
                        $property = $properties[$name] ?? [];
                        $value[$name] = $this->minimalValueFor(is_array($property) ? $property : []);
                    }

                    return $value;
                }

                if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
                    return $schema['enum'][0];
                }

                return match ($type) {
                    'array' => [],
                    'string' => 'Generated text',
                    'integer' => 0,
                    'number' => 0.0,
                    'boolean' => false,
                    default => null,
                };
            }

            /**
             * @return array{status: string, content: string}
             */
            public function completeFreeform(string $systemPrompt, string $userPrompt): array
            {
                return ['status' => 'success', 'content' => 'Generated text'];
            }
        });
    }

    /**
     * For tests that exercise the real driver selection (with Http::fake() standing in for the
     * provider's API).
     */
    protected function useRealAiDrivers(): void
    {
        if ($this->realVectorDriver) {
            $this->app->scoped(VectorDriver::class, $this->realVectorDriver);
        }

        if ($this->realLlmDriver) {
            $this->app->scoped(LlmDriver::class, $this->realLlmDriver);
        }
    }
}
