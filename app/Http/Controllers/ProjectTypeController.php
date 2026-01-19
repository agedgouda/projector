<?php

namespace App\Http\Controllers;

use App\Models\ProjectType;
use App\Models\AiTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectTypeController extends Controller
{
    /**
     * List all protocols
     */
    public function index()
    {
        return Inertia::render('ProjectTypes/Index', [
            'projectTypes' => ProjectType::withCount('projects')
                ->orderBy('name')
                ->get(),
            'aiTemplates' => AiTemplate::select('id', 'name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('ProjectTypes/Show', [
            'aiTemplates' => AiTemplate::select('id', 'name')->get(),
        ]);
    }

    /**
     * Dedicated configuration page for a single protocol
     */
    public function edit(ProjectType $projectType)
    {
        return Inertia::render('ProjectTypes/Show', [
            'projectType' => $projectType->loadCount('projects'),
            'aiTemplates' => AiTemplate::select('id', 'name')->orderBy('name')->get(),
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
            'workflow' => 'nullable|array',
            'workflow.*.from_key' => 'required|string',
            'workflow.*.to_key' => 'required|string',
            'workflow.*.ai_template_id' => 'nullable|exists:ai_templates,id',
        ]);

        $type = ProjectType::create($validated);

        // Redirect to the dedicated show page after creation
        return redirect()->route('project-types.index')
            ->with('success', 'Protocol initialized.');
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
            'workflow' => 'nullable|array',
            'workflow.*.from_key' => 'required|string',
            'workflow.*.to_key' => 'required|string',
            'workflow.*.ai_template_id' => 'nullable|exists:ai_templates,id',
        ]);

        $projectType->update($validated);

        return redirect()->route('project-types.index')
            ->with('success', 'Protocol updated.');
    }

    public function destroy(ProjectType $projectType)
    {
        if ($projectType->projects()->exists()) {
            return back()->withErrors([
                'delete' => "The protocol '{$projectType->name}' is in use and cannot be deleted."
            ]);
        }

        $projectType->delete();

        return redirect()->route('project-types.index');
    }
}
