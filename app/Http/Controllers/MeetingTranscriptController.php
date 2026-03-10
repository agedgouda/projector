<?php

namespace App\Http\Controllers;

use App\Jobs\ImportMeetingTranscript;
use App\Models\Document;
use App\Models\Project;
use App\Services\MeetingTranscriptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MeetingTranscriptController extends Controller
{
    /**
     * Show available recordings and already-imported transcripts for a project.
     */
    public function index(Project $project, MeetingTranscriptService $service)
    {
        Gate::authorize('view', $project);

        $organization = $project->client->organization;

        // IDs of recordings already imported into this project
        $importedIds = $project->documents()
            ->whereNotNull('metadata->recording_id')
            ->get(['metadata'])
            ->pluck('metadata.recording_id')
            ->filter()
            ->values();

        // Already-imported meeting documents (with a recording_id in metadata)
        $imported = $project->documents()
            ->whereNotNull('metadata->recording_id')
            ->latest()
            ->get(['id', 'name', 'metadata', 'created_at', 'processed_at']);

        $recordings = [];
        $providerError = null;

        if ($organization?->meeting_provider) {
            try {
                $since = now()->subDays(30);
                $recordings = $service->listRecordings($organization, $since);
            } catch (\Throwable $e) {
                $providerError = $e->getMessage();
            }
        }

        return inertia('Projects/Transcripts', [
            'project' => $project->load(['type', 'client.organization']),
            'recordings' => $recordings,
            'imported' => $imported,
            'importedIds' => $importedIds,
            'providerError' => $providerError,
            'provider' => $organization?->meeting_provider,
        ]);
    }

    /**
     * Queue an import job for a specific recording.
     */
    public function store(Request $request, Project $project)
    {
        Gate::authorize('create', [\App\Models\Document::class, $project]);

        $validated = $request->validate([
            'recording_id' => 'required|string',
            'title' => 'required|string|max:255',
            'started_at' => 'required|string',
        ]);

        // Prevent duplicate imports
        $alreadyImported = $project->documents()
            ->where('metadata->recording_id', $validated['recording_id'])
            ->exists();

        if ($alreadyImported) {
            return back()->withErrors(['recording_id' => 'This recording has already been imported.']);
        }

        // Create a placeholder document immediately so the UI can track progress.
        $document = $project->documents()->create([
            'type' => 'transcript',
            'name' => $validated['title'],
            'content' => '',
            'metadata' => [
                'recording_id' => $validated['recording_id'],
                'provider' => $project->client->organization->meeting_provider,
                'meeting_date' => $validated['started_at'],
            ],
        ]);

        ImportMeetingTranscript::dispatch($document, $validated['recording_id']);

        return back()->with('success', "Importing \"{$validated['title']}\"…");
    }
}
