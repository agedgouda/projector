<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Models\TusUpload;
use App\Services\RecordingIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Our own minimal server-side implementation of the tus resumable-upload protocol's
 * "creation" and "concatenation" extensions (https://tus.io/protocols/resumable-upload) — not
 * the "core" open-ended-stream shape tus is usually used for. `ankitpokhrel/tus-php` (the only
 * real PHP server implementation) can't be installed on this app (its last release predates
 * this app's Symfony 8, and there's a hard, unresolvable dependency conflict); the only current
 * alternative is GPL-licensed, which isn't a call to make unilaterally for a commercial
 * product. Hand-rolling just the subset actually needed sidesteps both.
 *
 * Shape: a browser capture is many small "partial" uploads, one per MediaRecorder interval,
 * each with a known size the moment it's produced — no need for tus's "deferred length"
 * extension at all. Each is uploaded with tus-js-client, which gives real retry/resume for
 * free. The moment the user stops recording, one "final" creation request concatenates all the
 * partials (Upload-Concat: final;<url1> <url2> ...) — handled synchronously here, since
 * stitching together a handful of small files is cheap — and hands the result straight to
 * RecordingIntakeService::storeFromPath(), the same intake the mobile/API single-shot uploads
 * already use.
 */
class TusUploadController extends Controller
{
    private const TUS_VERSION = '1.0.0';

    public function __construct(private readonly RecordingIntakeService $recordings) {}

    public function options(Project $project): Response
    {
        Gate::authorize('create', [Document::class, $project]);

        $configuredMax = config('media-library.max_file_size');

        return response('', 204, [
            'Tus-Resumable' => self::TUS_VERSION,
            'Tus-Version' => self::TUS_VERSION,
            'Tus-Extension' => 'creation,concatenation',
            'Tus-Max-Size' => is_numeric($configuredMax) ? (string) (int) $configuredMax : '',
        ]);
    }

    public function create(Request $request, Project $project): Response|JsonResponse
    {
        Gate::authorize('create', [Document::class, $project]);

        $concat = $request->header('Upload-Concat');

        if (is_string($concat) && str_starts_with($concat, 'final;')) {
            return $this->createFinal($project, $concat);
        }

        return $this->createPartial($request, $project);
    }

    public function head(Project $project, TusUpload $upload): Response
    {
        Gate::authorize('create', [Document::class, $project]);
        abort_if($upload->project_id !== $project->id, 404);

        return response('', 200, [
            'Tus-Resumable' => self::TUS_VERSION,
            'Upload-Offset' => (string) $upload->upload_offset,
            'Upload-Length' => (string) $upload->upload_length,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function patch(Request $request, Project $project, TusUpload $upload): Response
    {
        Gate::authorize('create', [Document::class, $project]);
        abort_if($upload->project_id !== $project->id, 404);
        abort_if($upload->kind !== 'partial' || $upload->isComplete(), 409);

        // A mismatch means the client's view of how much we already have is stale (e.g. a
        // previous PATCH's response never reached it) — 409 is what tells tus-js-client to HEAD
        // this same URL and resume from our real offset instead of assuming its own is right.
        $rawOffset = $request->header('Upload-Offset');
        $clientOffset = is_string($rawOffset) && $rawOffset !== '' ? (int) $rawOffset : null;
        if ($clientOffset !== $upload->upload_offset) {
            return response('', 409, ['Tus-Resumable' => self::TUS_VERSION]);
        }

        $body = $request->getContent();
        $newOffset = $upload->upload_offset + strlen($body);

        if ($newOffset > $upload->upload_length) {
            return response('', 400, ['Tus-Resumable' => self::TUS_VERSION]);
        }

        file_put_contents(Storage::disk('local')->path($upload->storage_path), $body, FILE_APPEND | LOCK_EX);

        $upload->upload_offset = $newOffset;
        if ($newOffset >= $upload->upload_length) {
            $upload->completed_at = now();
        }
        $upload->save();

        return response('', 204, [
            'Tus-Resumable' => self::TUS_VERSION,
            'Upload-Offset' => (string) $upload->upload_offset,
        ]);
    }

    private function createPartial(Request $request, Project $project): Response
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $rawLength = $request->header('Upload-Length');
        $length = is_string($rawLength) ? (int) $rawLength : 0;
        abort_if($length <= 0, 400, 'Upload-Length is required.');

        $configuredMax = config('media-library.max_file_size');
        if (is_numeric($configuredMax) && $length > (int) $configuredMax) {
            return response('', 413, ['Tus-Resumable' => self::TUS_VERSION]);
        }

        $id = (string) Str::orderedUuid();
        $path = 'tus-uploads/'.$id.'.partial';
        Storage::disk('local')->put($path, '');

        $rawMetadata = $request->header('Upload-Metadata');

        $upload = TusUpload::create([
            'id' => $id,
            'project_id' => $project->id,
            'creator_id' => $user->id,
            'kind' => 'partial',
            'upload_length' => $length,
            'upload_offset' => 0,
            'storage_path' => $path,
            'metadata' => $this->parseMetadata(is_string($rawMetadata) ? $rawMetadata : ''),
        ]);

        return response('', 201, [
            'Tus-Resumable' => self::TUS_VERSION,
            'Location' => route('projects.browser-recordings.tus.chunk', ['project' => $project, 'upload' => $upload->id]),
        ]);
    }

    /**
     * "final;<url1> <url2> ..." — concatenates the referenced (already-complete) partial
     * uploads, in the given order, into one file and immediately runs it through the normal
     * recording intake. Deliberately returns a JSON `recording` body (not the empty body /
     * Location header a tus response normally carries) — nothing downstream ever needs to
     * HEAD/PATCH this "final" resource again, and our own frontend is the only client of this
     * particular request, so it reads the resulting document straight from this response
     * instead of a second round trip.
     */
    private function createFinal(Project $project, string $concatHeader): JsonResponse
    {
        $urls = array_values(array_filter(explode(' ', trim(substr($concatHeader, strlen('final;'))))));
        abort_if($urls === [], 400, 'Upload-Concat: final must reference at least one partial upload.');

        $ids = array_map(fn (string $url): string => basename((string) parse_url($url, PHP_URL_PATH)), $urls);

        $partialsById = TusUpload::whereIn('id', $ids)
            ->where('project_id', $project->id)
            ->where('kind', 'partial')
            ->get()
            ->keyBy('id');

        // Client-given order, not the DB's — order is what makes this a coherent recording
        // rather than shuffled audio. Built as a plain array (not a Collection) so a found,
        // complete partial is provably non-null below without an inline type override.
        $orderedPartials = [];
        foreach ($ids as $id) {
            $partial = $partialsById->get($id);
            abort_if(
                $partial === null || ! $partial->isComplete(),
                400,
                'One or more referenced chunks were not found or are not finished uploading.'
            );
            $orderedPartials[] = $partial;
        }

        $metadata = $orderedPartials[0]->metadata ?? [];
        $finalPath = 'tus-uploads/'.(string) Str::orderedUuid().'.final';
        $absoluteFinalPath = Storage::disk('local')->path($finalPath);

        $out = fopen($absoluteFinalPath, 'wb');
        abort_if($out === false, 500, 'Could not create the assembled recording file.');

        $totalBytes = 0;
        foreach ($orderedPartials as $partial) {
            $in = fopen(Storage::disk('local')->path($partial->storage_path), 'rb');
            abort_if($in === false, 500, 'Could not read an uploaded chunk.');

            stream_copy_to_stream($in, $out);
            fclose($in);
            $totalBytes += $partial->upload_length;
        }
        fclose($out);

        $document = $this->recordings->storeFromPath(
            $project,
            $absoluteFinalPath,
            $totalBytes,
            is_string($metadata['name'] ?? null) ? $metadata['name'] : null,
            is_string($metadata['recorded_at'] ?? null) ? $metadata['recorded_at'] : null,
            'browser_capture',
        );

        // addMedia() (inside storeFromPath) already consumed and deleted the concatenated
        // final file as part of attaching it to the Document — only the now-redundant partial
        // chunks and their rows need cleaning up here.
        foreach ($orderedPartials as $partial) {
            Storage::disk('local')->delete($partial->storage_path);
        }
        TusUpload::whereIn('id', $ids)->delete();

        return response()->json([
            'recording' => $this->recordings->summarize($document),
        ], 201, ['Tus-Resumable' => self::TUS_VERSION]);
    }

    /**
     * "key1 base64value1,key2 base64value2" per the tus creation extension.
     *
     * @return array<string, string>
     */
    private function parseMetadata(string $header): array
    {
        $result = [];

        foreach (array_filter(explode(',', $header)) as $pair) {
            [$key, $value] = array_pad(explode(' ', trim($pair), 2), 2, '');
            $decoded = base64_decode($value, true);

            if ($key !== '' && $decoded !== false) {
                $result[$key] = $decoded;
            }
        }

        return $result;
    }
}
