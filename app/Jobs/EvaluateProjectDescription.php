<?php

namespace App\Jobs;

use App\Contracts\LlmDriver;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateProjectDescription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = 10;

    public function __construct(public Project $project) {}

    public function handle(): void
    {
        $this->project->loadMissing('client.organization');
        $this->project->client->organization?->applyDriverConfig();

        /** @var LlmDriver $llmDriver */
        $llmDriver = app(LlmDriver::class);

        $systemPrompt = 'You evaluate project descriptions to determine if they provide useful context for AI document generation. Set "title" to exactly "good" if the description conveys what the project is, who it is for, or what it aims to achieve — even a short, specific description qualifies. Set "title" to exactly "vague" only if the description is so generic that an AI could not meaningfully tailor output to it (e.g. "A project", "Internal tool", "New website"). When vague, use the "criteria" array to list 2-3 short, actionable suggestions for what the user could add to improve it — write them as helpful prompts, not criticisms. Use the content field for a one-sentence explanation.';

        $userPrompt = "Evaluate this project description: \"{$this->project->description}\"\n\nCRITICAL: You must return a JSON array. Each object in the array MUST use exactly these keys: \"title\", \"content\", and \"criteria\".";

        $result = $llmDriver->call($systemPrompt, $userPrompt);

        if (($result['status'] ?? '') !== 'success' || empty($result['content'])) {
            return;
        }

        $verdict = strtolower(trim($result['content'][0]['title'] ?? ''));
        $quality = in_array($verdict, ['good', 'vague']) ? $verdict : null;

        if ($quality) {
            $this->project->updateQuietly(['description_quality' => $quality]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('EvaluateProjectDescription failed: '.$exception->getMessage(), [
            'project_id' => $this->project->id,
        ]);
    }
}
