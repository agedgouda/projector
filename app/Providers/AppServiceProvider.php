<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Document;
use App\Observers\DocumentObserver;
use App\Services\Ai\ProjectAiService;
use App\Services\VectorService;
use App\Contracts\VectorDriver;
use App\Contracts\LlmDriver;
use App\Services\Ai\Drivers\OpenAiLlmDriver;
use App\Services\Ai\Drivers\GeminiLlmDriver;
use App\Services\Ai\Drivers\OllamaLlmDriver;
use App\Services\Vectors\GeminiDriver;
use App\Services\Vectors\OllamaDriver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Cache;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 1. Vector Driver Resolution (Embedding/Search)
        $this->app->scoped(VectorDriver::class, function (Application $app) {
            $name = config('services.vector_driver', 'openai');

            return match ($name) {
                'openai' => $app->make(OpenAiLlmDriver::class),
                'gemini' => $app->make(GeminiDriver::class),
                'ollama' => $app->make(OllamaDriver::class),
                default  => throw new \InvalidArgumentException("Vector Driver [{$name}] not supported."),
            };
        });

        // 2. LLM Driver Resolution (Extraction/Chat)
        $this->app->scoped(LlmDriver::class, function (Application $app) {
            $driverName = config('services.llm_driver', 'openai');

            // --- CIRCUIT BREAKER CHECK ---
            // Laravel 12 recommends using the Cache facade or atomic locks for this
            if (Cache::get("circuit_breaker:{$driverName}:tripped")) {
                throw new \RuntimeException("Circuit Breaker: {$driverName} is currently offline to prevent worker stalling.");
            }

            return match ($driverName) {
                'openai' => $app->make(OpenAiLlmDriver::class),
                'gemini' => $app->make(GeminiLlmDriver::class),
                'ollama' => $app->make(OllamaLlmDriver::class),
                default  => throw new \RuntimeException("Unsupported LLM Driver: [{$driverName}]"),
            };
        });

        // 3. Registering core services with Zero-Config Resolution
        $this->app->scoped(VectorService::class);
        $this->app->scoped(ProjectAiService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production for secure API callbacks
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Register Model Observers
        Document::observe(DocumentObserver::class);
    }
}
