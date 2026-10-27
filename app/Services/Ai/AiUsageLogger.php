<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

class AiUsageLogger
{
    public function log(
        string $driver,
        string $model,
        string $type,
        int $inputTokens,
        int $outputTokens,
        ?Project $project = null,
        ?string $organizationId = null,
    ): void {
        try {
            $costUsd = $this->calculateCost($driver, $model, $inputTokens, $outputTokens);
            $organizationId = $project?->client?->organization_id ?? $organizationId;

            AiUsageLog::create([
                'organization_id' => $organizationId,
                'membership_tier' => $organizationId ? Organization::query()->whereKey($organizationId)->value('membership_tier') : null,
                'client_id' => $project?->client_id,
                'project_id' => $project?->id,
                'driver' => $driver,
                'model' => $model,
                'type' => $type,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => $costUsd,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AiUsageLogger failed: '.$e->getMessage());
        }
    }

    /**
     * A finished transcription, billed by audio length rather than tokens (see
     * config/ai_costs.php's 'transcription' rates).
     */
    public function logTranscription(
        string $driver,
        string $model,
        int $audioSeconds,
        ?Project $project = null,
        ?string $organizationId = null,
    ): void {
        try {
            $organizationId = $project?->client->organization_id ?? $organizationId;

            AiUsageLog::create([
                'organization_id' => $organizationId,
                'membership_tier' => $organizationId ? Organization::query()->whereKey($organizationId)->value('membership_tier') : null,
                'client_id' => $project?->client_id,
                'project_id' => $project?->id,
                'driver' => $driver,
                'model' => $model,
                'type' => 'transcription',
                'audio_seconds' => $audioSeconds,
                'cost_usd' => $this->calculateTranscriptionCost($driver, $model, $audioSeconds),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AiUsageLogger failed: '.$e->getMessage());
        }
    }

    private function calculateTranscriptionCost(string $driver, string $model, int $audioSeconds): float
    {
        $rates = config("ai_costs.transcription.{$driver}");

        if (! is_array($rates)) {
            return 0.0;
        }

        $models = is_array($rates['models'] ?? null) ? $rates['models'] : [];
        $modelRate = $models[$model] ?? $rates['fallback'] ?? 0;
        $speakerLabelsRate = $rates['speaker_labels'] ?? 0;
        $hourlyRate = (is_numeric($modelRate) ? (float) $modelRate : 0.0) + (is_numeric($speakerLabelsRate) ? (float) $speakerLabelsRate : 0.0);

        return ($audioSeconds / 3600) * $hourlyRate;
    }

    private function calculateCost(string $driver, string $model, int $inputTokens, int $outputTokens): float
    {
        $rates = config("ai_costs.{$driver}.{$model}");

        if (! $rates) {
            return 0.0;
        }

        $inputCost = ($inputTokens / 1_000_000) * $rates['input'];
        $outputCost = ($outputTokens / 1_000_000) * $rates['output'];

        return $inputCost + $outputCost;
    }
}
