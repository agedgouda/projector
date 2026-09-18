<?php

namespace App\Console\Commands;

use App\Models\TusUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneAbandonedTusUploads extends Command
{
    /**
     * Scratch chunks are meant to live for the length of one recording session, not to be
     * durable storage — a much shorter window than PruneUnapprovedRecordings' 30-day default
     * for actual confirmed/unconfirmed audio.
     */
    private const RETENTION_HOURS = 24;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:prune-abandoned-tus-uploads';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete scratch chunk files (and their tracking rows) for browser recordings that were started but never finished — a crashed tab, a closed laptop, or a recording the user just abandoned mid-capture.';

    public function handle(): int
    {
        // Every row a *successful* recording creates — partial or final — gets deleted the
        // moment TusUploadController::createFinal() finishes using it, all within one request.
        // The only way a row survives past that is an abandoned session (never finalized) or a
        // mid-request failure, so age alone is a reliable signal here — no need to also check
        // kind/completed_at.
        $uploads = TusUpload::where('created_at', '<', now()->subHours(self::RETENTION_HOURS))->get();

        foreach ($uploads as $upload) {
            Storage::disk('local')->delete($upload->storage_path);
            Log::info('PruneAbandonedTusUploads: deleted abandoned recording chunk.', [
                'tus_upload_id' => $upload->id,
                'project_id' => $upload->project_id,
                'kind' => $upload->kind,
            ]);
        }

        TusUpload::whereIn('id', $uploads->pluck('id'))->delete();

        $this->info("Pruned {$uploads->count()} abandoned tus upload(s) older than ".self::RETENTION_HOURS.' hour(s).');

        return self::SUCCESS;
    }
}
