<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
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
    ): void {
        try {
            $costUsd = $this->calculateCost($driver, $model, $inputTokens, $outputTokens);

            AiUsageLog::create([
                'organization_id' => $project?->client?->organization_id,
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
