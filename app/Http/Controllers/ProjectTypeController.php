<?php

namespace App\Http\Controllers;

use App\Models\AiTemplate;
use App\Models\Organization;
use App\Models\ProjectType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectTypeController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', ProjectType::class);

        $user = auth()->user();
        $query = ProjectType::withCount('projects')->orderBy('name');

        if ($user->hasRole('super-admin')) {
            $query->with('organization');
        } else {
            $query->where('organization_id', getPermissionsTeamId());
        }

        return inertia('ProjectTypes/Index', [
            'projectTypes' => $query->get(),
            'aiTemplates' => AiTemplate::select('id', 'name')->get(),
            'organizations' => $user->hasRole('super-admin')
                ? Organization::orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', ProjectType::class);

        $user = auth()->user();

        return inertia('ProjectTypes/Show', [
            'aiTemplates' => AiTemplate::select('id', 'name')->orderBy('name')->get(),
            'organizations' => $user->hasRole('super-admin')
                ? Organization::orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function edit(ProjectType $projectType)
    {
        Gate::authorize('update', $projectType);

        $user = auth()->user();

        return inertia('ProjectTypes/Show', [
            'projectType' => $projectType->load('lifecycleSteps')->loadCount('projects'),
            'aiTemplates' => AiTemplate::select('id', 'name')->orderBy('name')->get(),
            'organizations' => $user->hasRole('super-admin')
                ? Organization::orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', ProjectType::class);

        $validated = $this->validateProtocol($request);
        $validated['organization_id'] = $this->resolveOrganizationId($request);

        $projectType = ProjectType::create($validated);
        $this->syncLifecycleSteps($projectType, $validated['lifecycle_steps'] ?? []);

        return to_route('project-types.edit', $projectType->id)
            ->with('success', 'Protocol created successfully.');
    }

    public function update(Request $request, ProjectType $projectType)
    {
        Gate::authorize('update', $projectType);

        $validated = $this->validateProtocol($request, $projectType->id);

        if (auth()->user()->hasRole('super-admin')) {
            $validated['organization_id'] = $request->input('organization_id') ?: null;
        } else {
            unset($validated['organization_id']);
        }

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

    public function duplicate(Request $request, ProjectType $projectType)
    {
        Gate::authorize('update', $projectType);

        $validated = $request->validate([
            'organization_id' => 'required|uuid|exists:organizations,id',
        ]);

        $copy = $projectType->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
        $copy->organization_id = $validated['organization_id'];
        $copy->save();

        foreach ($projectType->lifecycleSteps as $step) {
            $copy->lifecycleSteps()->create($step->only(['order', 'label', 'description', 'color']));
        }

        return to_route('project-types.edit', $copy->id)->with('success', 'Protocol duplicated.');
    }

    /**
     * Resolve the organization_id to set on a new project type.
     */
    private function resolveOrganizationId(Request $request): ?string
    {
        if (auth()->user()->hasRole('super-admin')) {
            return $request->input('organization_id') ?: null;
        }

        return getPermissionsTeamId();
    }

    /**
     * Dry up the complex validation logic
     */
    protected function validateProtocol(Request $request, $id = null): array
    {
        $user = auth()->user();
        $orgId = $user->hasRole('super-admin')
            ? ($request->input('organization_id') ?: null)
            : getPermissionsTeamId();

        $uniqueRule = Rule::unique('project_types', 'name')->ignore($id);
        if ($orgId === null) {
            $uniqueRule->whereNull('organization_id');
        } else {
            $uniqueRule->where('organization_id', $orgId);
        }

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                $uniqueRule,
            ],
            'icon' => 'nullable|string|max:100',

            // Document Schema
            'document_schema' => 'required|array|min:1',
            'document_schema.*.label' => 'required|string',
            'document_schema.*.key' => 'required|string',
            'document_schema.*.is_task' => 'required|boolean',

            // Workflow
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
