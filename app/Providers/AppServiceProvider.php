<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\Document;
use App\Observers\DocumentObserver;
use App\Services\Ai\ProjectAiService;
use App\Services\VectorService;
use App\Contracts\VectorDriver; // Make sure this interface exists
use App\Services\Vectors\GeminiDriver;
use App\Services\Vectors\OllamaDriver;
use Illuminate\Contracts\Foundation\Application;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 1. Register the Driver based on config
        $this->app->scoped(VectorDriver::class, function (Application $app) {
            $name = config('services.vector_driver', 'gemini');

            return match ($name) {
                'gemini' => new GeminiDriver(),
                'ollama' => new OllamaDriver(),
                default  => throw new \InvalidArgumentException("Driver [{$name}] not supported."),
            };
        });

        // 2. Register VectorService
        // Laravel will automatically inject the VectorDriver we defined above
        $this->app->scoped(VectorService::class);

        // 3. Register ProjectAiService
        $this->app->scoped(ProjectAiService::class, function (Application $app) {
            return new ProjectAiService($app->make(VectorService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Document::observe(DocumentObserver::class);
    }
}
