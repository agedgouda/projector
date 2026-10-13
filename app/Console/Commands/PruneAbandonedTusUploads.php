<?php

namespace App\Console\Commands;

use App\Jobs\AssembleBrowserRecording;
use App\Models\Document;
use App\Models\Project;
use App\Models\TusUpload;
use App\Services\RecordingIntakeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneAbandonedTusUploads extends Command
{
    /**
     * No new chunk for this long means the recording is over — the browser uploads one every
     * ~20 seconds while it's running.
     */
    private const RECOVER_AFTER_IDLE_HOURS = 2;

    /**
     * A finished recording whose audio still isn't attached this long after Stop gets its
     * assembly retried.
     */
    private const RETRY_ASSEMBLY_AFTER_MINUTES = 60;

    /**
     * A chunk that never finished uploading holds only a fragment of audio that can't be
     * joined onto anything, so it's only worth keeping while its upload might still resume.
     */
    private const INCOMPLETE_RETENTION_HOURS = 24;

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
    protected $description = 'Make sure no browser recording is lost: save recordings whose tab closed before Stop as "Recovered recording" documents, retry any whose audio never got attached, then delete chunks nothing needs any more.';

    public function handle(RecordingIntakeService $recordings): int
    {
        $recovered = $this->recoverUnfinishedRecordings($recordings);
        $retried = $this->retryUnassembledRecordings();
        $deleted = $this->deleteUnneededChunks();

        $this->info("Recovered {$recovered} recording(s), retried {$retried}, deleted {$deleted} unneeded chunk(s).");

        return self::SUCCESS;
    }

    /**
     * Chunks nobody clicked Stop for — a closed tab, a crashed browser, a laptop that went to
     * sleep — grouped back into their recordings (same person, same project, same start time)
     * and saved exactly as if Stop had been clicked.
     */
    private function recoverUnfinishedRecordings(RecordingIntakeService $recordings): int
    {
        $groups = TusUpload::query()
            ->whereNull('claimed_at')
            ->where('kind', 'partial')
            ->whereNotNull('completed_at')
            ->get()
            ->groupBy(fn (TusUpload $chunk): string => implode('|', [
                $chunk->project_id,
                $chunk->creator_id,
                $chunk->metadata['recorded_at'] ?? '',
            ]));

        $recovered = 0;
        $idleSince = now()->subHours(self::RECOVER_AFTER_IDLE_HOURS);

        foreach ($groups as $group) {
            $lastActivity = $group->max(fn (TusUpload $chunk): int => $chunk->updated_at?->getTimestamp() ?? 0);
            if ($lastActivity > $idleSince->getTimestamp()) {
                continue;
            }

            $chunks = TusUpload::inRecordingOrder($group);
            $first = $chunks->first();
            $project = $first?->project;
            if ($first === null || ! $project instanceof Project) {
                continue;
            }

            $document = $this->saveRecoveredRecording($recordings, $project, $chunks, $first);
            AssembleBrowserRecording::dispatch($document);
            $recovered++;

            Log::warning('PruneAbandonedTusUploads: recovered a browser recording that was never stopped.', [
                'document_id' => $document->id,
                'project_id' => $project->id,
                'chunks' => $chunks->count(),
            ]);
        }

        return $recovered;
    }

    /**
     * @param  Collection<int, TusUpload>  $chunks
     */
    private function saveRecoveredRecording(RecordingIntakeService $recordings, Project $project, Collection $chunks, TusUpload $first): Document
    {
        $recordedAt = $first->metadata['recorded_at'] ?? null;
        $startedAt = is_string($recordedAt) ? Carbon::parse($recordedAt) : $first->created_at;

        return DB::transaction(function () use ($recordings, $project, $chunks, $recordedAt, $startedAt): Document {
            $document = $recordings->startRecording(
                $project,
                'Recovered recording — '.($startedAt ?? now())->format('m/d/Y g:ia'),
                is_string($recordedAt) ? $recordedAt : null,
            );

            TusUpload::whereIn('id', $chunks->pluck('id'))->update([
                'document_id' => $document->id,
                'claimed_at' => now(),
            ]);

            return $document;
        });
    }

    /**
     * Recordings saved at Stop (or recovered above) whose audio still isn't attached — the
     * assembly job ran out of retries, or never ran. Too-large recordings are left alone;
     * their chunks are kept until someone deals with them by hand.
     */
    private function retryUnassembledRecordings(): int
    {
        $documentIds = TusUpload::query()
            ->whereNotNull('document_id')
            ->where('claimed_at', '<', now()->subMinutes(self::RETRY_ASSEMBLY_AFTER_MINUTES))
            ->distinct()
            ->pluck('document_id');

        $retried = 0;

        foreach (Document::whereIn('id', $documentIds)->get() as $document) {
            if (($document->metadata['audio_too_large'] ?? false) === true) {
                continue;
            }

            // Also tidies up after a document whose audio did get attached — the job deletes
            // the leftover chunks in that case instead of assembling anything.
            AssembleBrowserRecording::dispatch($document);
            $retried++;
        }

        return $retried;
    }

    /**
     * Only chunks that can't be part of any recording any more: ones whose recording was
     * deleted by a person, and fragments whose upload never finished.
     */
    private function deleteUnneededChunks(): int
    {
        $staleBefore = now()->subHours(self::INCOMPLETE_RETENTION_HOURS);

        $chunks = TusUpload::query()
            ->where(fn ($query) => $query->whereNotNull('claimed_at')->whereNull('document_id'))
            ->orWhere(fn ($query) => $query
                ->whereNull('claimed_at')
                ->where('updated_at', '<', $staleBefore)
                ->where(fn ($query) => $query->whereNull('completed_at')->orWhere('kind', 'final')))
            ->get();

        foreach ($chunks as $chunk) {
            Storage::disk('local')->delete($chunk->storage_path);
            Log::info('PruneAbandonedTusUploads: deleted an unneeded recording chunk.', [
                'tus_upload_id' => $chunk->id,
                'project_id' => $chunk->project_id,
                'reason' => $chunk->claimed_at !== null ? 'recording deleted' : 'upload never finished',
            ]);
        }

        TusUpload::whereIn('id', $chunks->pluck('id'))->delete();

        return $chunks->count();
    }
}
