<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoSamples;
use App\Services\Demo\DemoTaskExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class GenerateDemoSampleResults extends Command
{
    /**
     * @var string
     */
    protected $signature = 'demo:generate-samples {sample? : Only regenerate this sample id}';

    /**
     * @var string
     */
    protected $description = "Run the demo page's sample meetings through the real task extraction and save the results, so visitors who pick an unedited sample don't trigger an AI call.";

    public function handle(DemoSamples $samples, DemoTaskExtractor $extractor): int
    {
        $only = $this->argument('sample');
        $ids = array_keys(DemoSamples::SAMPLES);

        if (is_string($only)) {
            if (! in_array($only, $ids, true)) {
                $this->error("Unknown sample [{$only}]. Available: ".implode(', ', $ids));

                return self::FAILURE;
            }

            $ids = [$only];
        }

        $failed = false;

        foreach ($ids as $id) {
            $sample = DemoSamples::SAMPLES[$id];

            try {
                $tasks = $extractor->extract(
                    $samples->notes($id),
                    Carbon::createFromFormat('Y-m-d', $sample['meeting_date']) ?? Carbon::today(),
                    $sample['team'],
                );
            } catch (Throwable $e) {
                $this->error("{$id}: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            file_put_contents(
                $samples->resultPath($id),
                json_encode(['generated_at' => now()->toIso8601String(), 'tasks' => $tasks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            );

            $this->info("{$id}: ".count($tasks).' tasks');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
