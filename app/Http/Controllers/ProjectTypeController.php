<?php

namespace App\Http\Controllers;

use App\Models\ProjectType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectTypeController extends Controller
{
    public function index()
    {
        return Inertia::render('ProjectTypes/Index', [
            'projectTypes' => ProjectType::withCount('projects')
                ->orderBy('name')
                ->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:project_types,name|max:255',
            'icon' => 'nullable|string|max:100',
            'document_schema' => 'nullable|array',
            'document_schema.*.label' => 'required|string',
            'document_schema.*.key' => 'required|string',
            'document_schema.*.required' => 'required|boolean',
        ]);

        ProjectType::create($validated);

        return redirect()->back()->with('success', 'Type created.');
    }

    public function update(Request $request, ProjectType $projectType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:project_types,name,' . $projectType->id,
            'icon' => 'nullable|string|max:100',
            'document_schema' => 'nullable|array',
            'document_schema.*.label' => 'required|string',
            'document_schema.*.key' => 'required|string',
            'document_schema.*.required' => 'required|boolean',
        ]);

        $projectType->update($validated);

        return redirect()->back()->with('success', 'Type updated.');
    }

    public function destroy(ProjectType $projectType)
    {
        if ($projectType->projects()->exists()) {
            // We use a specific key for the error so the UI can catch it
            return back()->withErrors([
                'delete' => "The type '{$projectType->name}' is currently in use by active projects and cannot be deleted."
            ]);
        }

        $projectType->delete();

        return back();
    }
}
