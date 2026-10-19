<?php

namespace App\Http\Controllers;

use App\Http\Requests\DemoExtractRequest;
use App\Services\Demo\DemoSamples;
use App\Services\Demo\DemoTaskExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The public demo: pick or paste meeting notes and see the tasks Projector drafts from them.
 * Unedited samples show precomputed results; anything else is a live, rate-limited AI run.
 */
class DemoController extends Controller
{
    public function show(DemoSamples $samples): Response
    {
        return Inertia::render('Demo/Index', [
            'samples' => $samples->all(),
            'tags' => DemoTaskExtractor::TAGS,
            'minNotesLength' => DemoExtractRequest::MIN_NOTES_LENGTH,
            'maxNotesLength' => DemoExtractRequest::MAX_NOTES_LENGTH,
            'turnstileSiteKey' => config('services.turnstile.site_key'),
            'parentOrigin' => config('services.demo.parent_origin'),
        ]);
    }

    public function extract(DemoExtractRequest $request, DemoTaskExtractor $extractor): JsonResponse
    {
        if (! $this->claimLiveRun()) {
            return response()->json([
                'message' => 'The live demo is busy right now. Try one of the samples, or come back tomorrow.',
            ], 503);
        }

        try {
            $tasks = $extractor->extract(
                $request->string('notes')->toString(),
                $request->date('meeting_date', 'Y-m-d') ?? Carbon::today(),
                $request->team(),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => "The AI didn't respond. Please try again in a moment.",
            ], 502);
        }

        return response()->json(['tasks' => $tasks]);
    }

    /**
     * Counts this run against the site-wide daily cap, which bounds what the demo can cost
     * regardless of how many visitors (or IPs) it gets.
     */
    private function claimLiveRun(): bool
    {
        $key = 'demo:live-runs:'.Carbon::today()->toDateString();
        $limit = config()->integer('services.demo.daily_live_limit', 300);

        Cache::add($key, 0, Carbon::tomorrow()->addHour());

        $runsToday = Cache::get($key, 0);

        if (is_numeric($runsToday) && (int) $runsToday >= $limit) {
            return false;
        }

        Cache::increment($key);

        return true;
    }
}
