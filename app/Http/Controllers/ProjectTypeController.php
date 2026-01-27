<?php

namespace App\Http\Controllers;

use App\Models\{ProjectType, AiTemplate};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
        ProjectType::create($validated);

        return back()->with('message', 'success');
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
            'name' => 'required|string|max:255|unique:project_types,name,' . $id,
            'icon' => 'nullable|string|max:100',
            'document_schema' => 'nullable|array',
            'document_schema.*.label' => 'required|string',
            'document_schema.*.key' => 'required|string',
            'document_schema.*.is_task' => 'required|boolean',
            'workflow' => 'nullable|array',
            'workflow.*.from_key' => 'required|string',
            'workflow.*.to_key' => 'required|string',
            'workflow.*.ai_template_id' => 'nullable|exists:ai_templates,id',
        ]);
    }
}
