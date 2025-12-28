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
        $project->load('client');

        return inertia('Projects/Show', [
            'project' => $project,
        ]);
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
        return redirect()->back();
    }
}
