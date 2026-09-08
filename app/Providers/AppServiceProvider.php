<?php

namespace App\Providers;

use App\Contracts\LlmDriver;
use App\Contracts\TranscriptionDriver;
use App\Contracts\VectorDriver;
use App\Models\Document;
use App\Models\OrgDocument;
use App\Models\Project;
use App\Models\ProjectType;
use App\Observers\DocumentObserver;
use App\Observers\OrgDocumentObserver;
use App\Observers\ProjectObserver;
use App\Observers\ProjectTypeObserver;
use App\Services\Ai\Drivers\GeminiLlmDriver;
use App\Services\Ai\Drivers\OllamaLlmDriver;
use App\Services\Ai\Drivers\OpenAiLlmDriver;
use App\Services\Ai\ProjectAiService;
use App\Services\Transcription\Drivers\AssemblyAiTranscriptionDriver;
use App\Services\Vectors\GeminiDriver;
use App\Services\Vectors\OllamaDriver;
use App\Services\VectorService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use PhpOffice\PhpWord\Settings as PhpWordSettings;

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

            // 'same' means use the same provider as the LLM driver
            if ($name === 'same') {
                $name = config('services.llm_driver', 'openai');
            }

            return match ($name) {
                'openai' => $app->make(OpenAiLlmDriver::class),
                'gemini' => $app->make(GeminiDriver::class),
                'ollama' => $app->make(OllamaDriver::class),
                default => throw new \InvalidArgumentException("Vector Driver [{$name}] not supported."),
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
                default => throw new \RuntimeException("Unsupported LLM Driver: [{$driverName}]"),
            };
        });

        // 3. Registering core services with Zero-Config Resolution
        $this->app->scoped(VectorService::class);
        $this->app->scoped(ProjectAiService::class);

        // 4. Transcription Driver Resolution (mobile recording -> text)
        $this->app->bind(TranscriptionDriver::class, AssemblyAiTranscriptionDriver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force the root URL only around actual queued-job execution — every route()/url()
        // call a job makes (Slack messages, digests, etc.) runs with no bound request at all,
        // so without this it falls back to whatever host the worker process happened to boot
        // with. Deliberately scoped to JobProcessing rather than applied unconditionally in
        // boot(): this app serves real web traffic through Octane, which — like Horizon — is a
        // long-lived CLI process, so `runningInConsole()` can't tell the two apart, and boot()
        // itself only ever runs once per process anyway. Forcing it unconditionally there once
        // clobbered asset/page URL generation for every real visitor for the process's entire
        // lifetime when APP_URL didn't exactly match how the app is actually reached. This way
        // real web requests (Octane) are never touched — only a job's own execution inside a
        // queue worker (Horizon) is affected.
        Event::listen(JobProcessing::class, function (): void {
            $appUrl = config('app.url');
            URL::forceRootUrl(is_string($appUrl) ? $appUrl : 'http://localhost');
        });

        // Force HTTPS in production for secure API callbacks
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // PhpWord defaults this to false, writing text content into the docx XML
        // completely unescaped — a task/document name containing "&" (e.g. "Q&A")
        // produces an invalid document.xml that Word refuses to open.
        PhpWordSettings::setOutputEscapingEnabled(true);

        // Register Model Observers
        Document::observe(DocumentObserver::class);
        OrgDocument::observe(OrgDocumentObserver::class);
        ProjectType::observe(ProjectTypeObserver::class);
        Project::observe(ProjectObserver::class);
    }
}
