<?php

namespace App\Http\Controllers;

use App\Models\{ProjectType, AiTemplate};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectTypeController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', ProjectType::class);

        return inertia('ProjectTypes/Index', [
            'projectTypes' => ProjectType::withCount('projects')
                ->orderBy('name')
                ->get(),
            'aiTemplates' => AiTemplate::select('id', 'name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', ProjectType::class);

//        return inertia('ProjectTypes/Create', [
        return inertia('ProjectTypes/Show', [
            'aiTemplates' => AiTemplate::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function edit(ProjectType $projectType)
    {
        Gate::authorize('update', $projectType);

        return inertia('ProjectTypes/Show', [
            'projectType' => $projectType->loadCount('projects'),
            'aiTemplates' => AiTemplate::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', ProjectType::class);

        $validated = $this->validateProtocol($request);
        $projectType = ProjectType::create($validated);

        // Redirect to the edit/show page for the newly created UUID
        return to_route('project-types.edit', $projectType->id)
            ->with('success', 'Protocol created successfully.');
    }

    public function update(Request $request, ProjectType $projectType)
    {
        Gate::authorize('update', $projectType);

        $validated = $this->validateProtocol($request, $projectType->id);
        $projectType->update($validated);

        return back()->with('message', 'success');
    }

    public function destroy(ProjectType $projectType)
    {
        Gate::authorize('delete', $projectType);

        $projectType->delete();

        return to_route('project-types.index')->with('success', 'Protocol deleted.');
    }

    /**
     * Dry up the complex validation logic
     */
    protected function validateProtocol(Request $request, $id = null)
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('project_types', 'name')->ignore($id),
            ],
            'icon' => 'nullable|string|max:100',

            // Document Schema
            'document_schema' => 'required|array|min:1',
            'document_schema.*.label' => 'required|string',
            'document_schema.*.key' => 'required|string',
            'document_schema.*.is_task' => 'required|boolean',

            // Workflow - REMOVED the 'exists:document_schema' part
            'workflow' => 'nullable|array',
            'workflow.*.from_key' => 'required|string',
            'workflow.*.to_key' => 'required|string',
            'workflow.*.ai_template_id' => 'nullable|exists:ai_templates,id',
        ]);
    }
}
