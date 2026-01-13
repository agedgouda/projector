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


    public function index2(Request $request)
    {
        $user = $request->user();

        // Get tasks assigned to the user, grouped by project
        $tasks = Task::where('assignee_id', $user->id)
            ->with(['project.client.users', 'document'])
            ->orderBy('due_at', 'asc')
            ->get();

        $projectGroups = $tasks->groupBy('project_id')->map(function ($group) {
            return [
                'project' => $group->first()->project,
                'tasks' => $group->values(),
            ];
        })->values();

        return Inertia::render('Dashboard/Index', [
            'projectGroups' => $projectGroups,
            'stats' => [
                'total_tasks' => $tasks->count(),
                'overdue' => $tasks->where('due_at', '<', now())->where('status', '!=', 'done')->count(),
            ]
        ]);
    }
}
