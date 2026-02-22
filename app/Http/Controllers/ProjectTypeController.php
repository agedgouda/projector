<?php

namespace App\Http\Controllers;

use App\Models\AiTemplate;
use App\Models\ProjectType;
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
            'projectType' => $projectType->load('lifecycleSteps')->loadCount('projects'),
            'aiTemplates' => AiTemplate::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', ProjectType::class);

        $validated = $this->validateProtocol($request);
        $projectType = ProjectType::create($validated);
        $this->syncLifecycleSteps($projectType, $validated['lifecycle_steps'] ?? []);

        // Redirect to the edit/show page for the newly created UUID
        return to_route('project-types.edit', $projectType->id)
            ->with('success', 'Protocol created successfully.');
    }

    public function update(Request $request, ProjectType $projectType)
    {
        Gate::authorize('update', $projectType);

        $validated = $this->validateProtocol($request, $projectType->id);
        $projectType->update($validated);
        $this->syncLifecycleSteps($projectType, $validated['lifecycle_steps'] ?? []);

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
    protected function validateProtocol(Request $request, $id = null): array
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

            // Lifecycle Steps
            'lifecycle_steps' => 'nullable|array',
            'lifecycle_steps.*.id' => 'nullable|integer|exists:lifecycle_steps,id',
            'lifecycle_steps.*.order' => 'required|integer|min:1',
            'lifecycle_steps.*.label' => 'required|string|max:100',
            'lifecycle_steps.*.description' => 'nullable|string|max:500',
            'lifecycle_steps.*.color' => 'nullable|string|max:50',
        ]);
    }

    /**
     * Sync lifecycle steps for a project type (create, update, delete).
     *
     * @param  array<int, array{id?: int|null, order: int, label: string, description?: string|null, color?: string|null}>  $steps
     */
    private function syncLifecycleSteps(ProjectType $projectType, array $steps): void
    {
        $stepIds = collect($steps)->pluck('id')->filter()->all();
        $projectType->lifecycleSteps()->whereNotIn('id', $stepIds)->delete();

        foreach ($steps as $step) {
            if (! empty($step['id'])) {
                $projectType->lifecycleSteps()->where('id', $step['id'])->update([
                    'order' => $step['order'],
                    'label' => $step['label'],
                    'description' => $step['description'] ?? null,
                    'color' => $step['color'] ?? null,
                ]);
            } else {
                $projectType->lifecycleSteps()->create([
                    'order' => $step['order'],
                    'label' => $step['label'],
                    'description' => $step['description'] ?? null,
                    'color' => $step['color'] ?? null,
                ]);
            }
        }
    }
}
