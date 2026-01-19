<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Document;
use App\Observers\DocumentObserver;
use App\Services\Ai\ProjectAiService;
use App\Services\VectorService;
use App\Contracts\VectorDriver;
use App\Services\Vectors\GeminiDriver;
use App\Services\Vectors\OllamaDriver;
use App\Contracts\LlmDriver;
use App\Services\Ai\Drivers\GeminiLlmDriver;
use App\Services\Ai\Drivers\OllamaLlmDriver;
use Illuminate\Contracts\Foundation\Application;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 1. Vector Driver Resolution
        $this->app->scoped(VectorDriver::class, function (Application $app) {
            $name = config('services.vector_driver', 'gemini');
            return match ($name) {
                'gemini' => new GeminiDriver(),
                'ollama' => new OllamaDriver(),
                default  => throw new \InvalidArgumentException("Vector Driver [{$name}] not supported."),
            };
        });

        // 2. LLM Driver Resolution (Moving logic out of ProjectAiService)
        $this->app->scoped(LlmDriver::class, function (Application $app) {
            $driverName = config('services.llm_driver', 'gemini');

            // --- CIRCUIT BREAKER CHECK ---
            // If we've had more than 5 failures in the last minute, "trip" the circuit
            if (cache()->get("circuit_breaker:{$driverName}:tripped")) {
                throw new \Exception("Circuit Breaker: {$driverName} is currently offline to prevent worker stalling.");
            }

            return match ($driverName) {
                'gemini' => new GeminiLlmDriver(),
                'ollama' => new OllamaLlmDriver(),
                default  => throw new \Exception("Unsupported Driver"),
            };
        });

        $this->app->scoped(VectorService::class);

        // 3. ProjectAiService (Standard Scoped Binding)
        $this->app->scoped(ProjectAiService::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
                URL::forceScheme('https');
            }
        Document::observe(DocumentObserver::class);
    }
}
