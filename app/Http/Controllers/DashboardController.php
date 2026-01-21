<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectType;
use Inertia\Inertia;
use Illuminate\Http\Request;

class DashboardController extends Controller
{

    public function index(Request $request)
    {
        $user = $request->user();

        $assignedDocuments = Document::where('assignee_id', $user->id)
            ->with(['project.client.users', 'creator'])
            ->orderBy('updated_at', 'desc')
            ->get();

        // Group by project_id so the frontend can loop through projects
        $groupedByProject = $assignedDocuments->groupBy('project_id')
            ->map(function ($docs) {
                // We take the first doc's project as the "header" for this group
                return [
                    'project' => $docs->first()->project,
                    'documents' => $docs->values(), // values() resets the keys to a clean array
                ];
            })->values();

        return Inertia::render('Dashboard/Index', [
            'projectGroups' => $groupedByProject,
            'projectTypes' => \App\Models\ProjectType::all(),
            'stats' => [
                'total' => $assignedDocuments->count(),
                'pending' => $assignedDocuments->whereNull('processed_at')->count(),
            ]
        ]);
    }

}
