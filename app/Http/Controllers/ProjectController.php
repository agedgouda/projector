<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Client;
use App\Models\Document;
use App\Models\ProjectType;
use App\Services\Ai\ProjectAiService;

use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Use scopes for both projects AND the client list (for dropdowns)
        $projects = Project::visibleTo($user)
            ->with(['client', 'documents', 'type'])
            ->latest()
            ->get();

        $clients = Client::visibleTo($user)->get();

        if (!$user->hasRole('admin') && $projects->isEmpty() && $clients->isEmpty()) {
            abort(404);
        }

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'clients' => $clients,
            'projectTypes' => ProjectType::all(),
        ]);
    }

    public function show(Project $project)
    {
        $project->load([
            'client.users',
            'type',
            'documents' => function ($query) {
                $query->with(['creator', 'editor', 'assignee', 'tasks.assignee'])
                    ->orderBy('created_at', 'desc');
            }
        ]);

        return inertia('Projects/Show', [
            'project' => $project,
            'projectTypes' => \App\Models\ProjectType::all(),
        ]);
    }

    public function update(Request $request, Project $project)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_type_id' => 'required|exists:project_types,id',
            'client_id' => 'sometimes|required|exists:clients,id',
            'status' => 'sometimes|string',
        ]);

        $project->update($validated);

        return redirect()->back()->with('success', 'Project updated successfully.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'client_id' => 'required|exists:clients,id',
            'project_type_id' => 'required|exists:project_types,id',
        ]);

        Project::create($validated);

        return redirect()->back();
    }

    public function destroy(Project $project)
    {
        $project->delete();
        $message = 'Project was successfully deleted.';

        // 1. Check if the frontend requested a specific destination
        if (request()->has('redirect_to')) {
            return redirect(request()->get('redirect_to'))->with('success', $message);
        }

        // 2. Fallback for the ClientProjects list (where 'back' is safe)
        return redirect()->back()->with('success', $message);
    }

    public function storeDocument(Request $request, Project $project)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|string', // tech_spec, functional_spec, etc.
            'content' => 'required|string',
        ]);

        $document = $project->documents()->create($validated);
        return back()->with('success', 'Document added and indexed.');
    }


    public function generate(Project $project, ProjectAiService $aiService)
    {
        // For now, we use the SoftwareStrategy explicitly
        $strategy = new \App\Services\Ai\Strategies\SoftwareStrategy();

        $results = $aiService->generateDeliverables($project, $strategy);

        return back()->with('aiResults', $results);
    }
}
