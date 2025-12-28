<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Client;
use App\Models\Document;
use App\Models\ProjectType;

use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectController extends Controller
{
    public function index()
    {
        return Inertia::render('Projects/Index', [
            'projects' => Project::with(['client', 'dna','type'])->latest()->get(),
            'clients' => Client::all(),
            'projectTypes' => ProjectType::all(),
            'documents' => Document::all(),
        ]);
    }

    public function show(Project $project)
    {
        $project->load(['client', 'type']);

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
            'description' => 'nullable|string',
            'client_id' => 'required|exists:clients,id',
            'project_type_id' => 'nullable|exists:project_types,id',
            'document_id' => 'nullable|exists:documents,id',
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
}
