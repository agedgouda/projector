<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneUnapprovedRecordings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prune-unapproved-recordings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete source audio for mobile-recorded notes that were never explicitly confirmed, once older than the configured retention window. Time-based fallback alongside the explicit "confirm" action.';

    public function handle(): int
    {
        $configuredDays = config('services.assemblyai.retention_days', 30);
        $retentionDays = is_numeric($configuredDays) ? (int) $configuredDays : 30;

        $recordings = Document::where('metadata->recording_source', 'mobile_recording')
            ->where(function ($query) {
                $query->whereNull('metadata->audio_status')
                    ->orWhere('metadata->audio_status', '!=', 'approved');
            })
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->whereHas('media', fn ($query) => $query->where('collection_name', 'recording'))
            ->get();

        foreach ($recordings as $document) {
            $document->clearMediaCollection('recording');
            Log::info('PruneUnapprovedRecordings: deleted unconfirmed recording audio.', ['document_id' => $document->id]);
        }

        $this->info("Pruned audio for {$recordings->count()} recording(s) older than {$retentionDays} day(s).");

        return self::SUCCESS;
    }
}
