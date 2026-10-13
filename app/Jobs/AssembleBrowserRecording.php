<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\TusUpload;
use App\Services\RecordingIntakeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Throwable;

/**
 * Joins a browser-captured recording's chunks (the TusUpload rows claimed by this document)
 * into one audio file and attaches it to the document, which then gets transcribed like any
 * other recording. The chunks are only deleted once the audio is safely attached — if any step
 * fails they stay put, and PruneAbandonedTusUploads retries this job later, so a recording
 * can't be lost to a failed assembly.
 */
class AssembleBrowserRecording implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * A deleted document means the user threw the recording away on purpose.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public Document $document) {}

    public function uniqueId(): string
    {
        return $this->document->id;
    }

    public function handle(RecordingIntakeService $recordings): void
    {
        $this->document->refresh();

        $chunks = $this->document->getFirstMedia('recording') === null
            ? TusUpload::inRecordingOrder(
                TusUpload::where('document_id', $this->document->id)->whereNotNull('completed_at')->get()
            )
            : null;

        if ($chunks === null) {
            $this->deleteChunks();

            return;
        }

        if ($chunks->isEmpty()) {
            $this->markFailed('None of this recording\'s audio reached the server.');

            return;
        }

        $path = Storage::disk('local')->path('tus-uploads/'.Str::orderedUuid().'.final');
        $totalBytes = $this->concatenate($chunks->all(), $path);

        $this->clearFailure();

        try {
            $recordings->attachAudio($this->document, $path);
        } catch (FileIsTooBig) {
            // Permanent, so not worth retrying — the chunks are kept regardless, and the flag
            // tells PruneAbandonedTusUploads to leave this one alone.
            @unlink($path);
            $configuredMax = config('media-library.max_file_size');
            $this->markFailed(
                'This recording ('.Number::fileSize($totalBytes).') is larger than the '
                .(is_numeric($configuredMax) ? Number::fileSize((int) $configuredMax) : 'allowed').' limit. The audio is saved — contact support to have it transcribed.',
                ['audio_too_large' => true],
            );

            return;
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        $this->deleteChunks();
    }

    public function failed(Throwable $exception): void
    {
        Log::error('AssembleBrowserRecording failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        $this->markFailed('We couldn\'t process this recording yet. The audio is saved and will be retried automatically.');
    }

    /**
     * @param  array<int, TusUpload>  $chunks
     */
    private function concatenate(array $chunks, string $path): int
    {
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not create the assembled recording file.');
        }

        $totalBytes = 0;

        try {
            foreach ($chunks as $chunk) {
                $in = fopen(Storage::disk('local')->path($chunk->storage_path), 'rb');
                if ($in === false) {
                    throw new RuntimeException("Could not read recording chunk {$chunk->id}.");
                }

                $totalBytes += (int) stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        return $totalBytes;
    }

    private function deleteChunks(): void
    {
        $chunks = TusUpload::where('document_id', $this->document->id)->get();

        foreach ($chunks as $chunk) {
            Storage::disk('local')->delete($chunk->storage_path);
        }

        TusUpload::whereIn('id', $chunks->pluck('id'))->delete();
    }

    private function clearFailure(): void
    {
        $metadata = $this->document->metadata ?? [];

        if (array_key_exists('transcription_error', $metadata)) {
            unset($metadata['transcription_error']);
            $this->document->update(['metadata' => $metadata]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function markFailed(string $message, array $extra = []): void
    {
        $this->document->refresh();

        $this->document->update([
            'metadata' => array_merge($this->document->metadata ?? [], ['transcription_error' => $message], $extra),
        ]);
    }
}
