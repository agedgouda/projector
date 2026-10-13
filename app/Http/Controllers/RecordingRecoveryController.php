<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Services\RecordingRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Try Again and Download Audio on the recording panel (see RecordingRecoveryService). The
 * {document} is whichever page the panel is on — a recording's transcript or its Meeting
 * Notes — and both act on the recording behind it.
 */
class RecordingRecoveryController extends Controller
{
    public function __construct(private readonly RecordingRecoveryService $recovery) {}

    public function retry(Project $project, Document $document): RedirectResponse
    {
        $recording = $this->authorizedRecording($project, $document);

        if (! $this->recovery->retry($recording)) {
            return back()->with('error', 'There\'s no saved audio left for this recording to try again with.');
        }

        return back()->with('success', 'Trying this recording again. The transcript will appear here when it\'s ready.');
    }

    public function download(Project $project, Document $document): Media|StreamedResponse
    {
        $recording = $this->authorizedRecording($project, $document);

        $download = $this->recovery->download($recording);
        abort_if($download === null, 404);

        return $download;
    }

    private function authorizedRecording(Project $project, Document $document): Document
    {
        abort_if($document->project_id !== $project->id, 404);

        $recording = $this->recovery->recordingFor($document);
        abort_if($recording === null, 404);

        Gate::authorize('recoverRecording', $recording);

        return $recording;
    }
}
