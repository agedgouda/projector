<?php

namespace App\Http\Controllers;

use App\Models\AiTemplate;
use App\Models\LifecycleTemplate;
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
        $query = ProjectType::orderBy('name');

        $query->with('organization');

        if (! $user->hasRole('super-admin')) {
            $orgId = getPermissionsTeamId();
            $query->where(function ($q) use ($orgId) {
                $q->whereNull('organization_id')
                    ->orWhere('organization_id', $orgId);
            });
        }

        return inertia('ProjectTypes/Index', [
            'projectTypes' => $query->get(),
            'aiTemplates' => $this->stepTemplateOptions(),
            'organizations' => $user->hasRole('super-admin')
                ? Organization::orderBy('name')->get(['id', 'name'])
                : [],
        ]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', ProjectType::class);

        $user = auth()->user();

        $template = ProjectType::with('lifecycleSteps')
            ->where('is_template', true)
            ->first();

        return inertia('ProjectTypes/Show', [
            'template' => $template,
            'aiTemplates' => $this->stepTemplateOptions(),
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
            'projectType' => $projectType->load('lifecycleSteps'),
            'aiTemplates' => $this->stepTemplateOptions(),
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
        Gate::authorize('duplicate', $projectType);

        $user = auth()->user();

        $orgId = $user->hasRole('super-admin')
            ? $request->validate(['organization_id' => 'required|uuid|exists:organizations,id'])['organization_id']
            : getPermissionsTeamId();

        $validated = ['organization_id' => $orgId];

        $copy = $projectType->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
        $copy->organization_id = $validated['organization_id'];
        $copy->save();

        $lifecycleTemplate = LifecycleTemplate::firstOrCreate([
            'organization_id' => $copy->organization_id,
            'name' => $copy->name,
        ]);

        foreach ($projectType->lifecycleSteps as $step) {
            $copy->lifecycleSteps()->create($step->only(['order', 'label', 'description', 'color']) + [
                'lifecycle_template_id' => $lifecycleTemplate->id,
            ]);
        }

        return to_route('project-types.edit', $copy->id)->with('success', 'Protocol duplicated.');
    }

    /**
     * The transformations a protocol step can use: global ones plus the viewer's own
     * organization's — a super-admin, who can set up a protocol for any organization, gets every
     * organization's, which the form narrows to the one the protocol belongs to. Each lists the
     * organizations that turned it off, so the form can hide it from new picks there.
     *
     * @return list<array{id: int, name: string, organization_id: string|null, disabled_organization_ids: list<string>}>
     */
    private function stepTemplateOptions(): array
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $isSuperAdmin = $user->hasRole('super-admin');
        $orgId = getPermissionsTeamId();
        $orgId = is_string($orgId) ? $orgId : null;

        return array_values(AiTemplate::query()
            ->when(! $isSuperAdmin, fn ($query) => $query->availableToOrganization($orgId))
            ->with(['disabledForOrganizations' => fn ($query) => $query
                ->when(! $isSuperAdmin, fn ($query) => $query->whereKey($orgId))
                ->select('organizations.id')])
            ->orderBy('name')
            ->get(['id', 'name', 'organization_id'])
            ->map(fn (AiTemplate $template) => [
                'id' => $template->id,
                'name' => $template->name,
                'organization_id' => $template->organization_id,
                'disabled_organization_ids' => array_values($template->disabledForOrganizations->map(fn (Organization $organization) => $organization->id)->all()),
            ])
            ->all());
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
            'is_template' => 'boolean',

            // Document Schema
            'document_schema' => 'required|array|min:1',
            'document_schema.*.label' => 'required|string',
            'document_schema.*.key' => 'required|string',
            'document_schema.*.is_task' => 'required|boolean',

            // Workflow
            'workflow' => 'nullable|array',
            'workflow.*.from_key' => 'required|string',
            'workflow.*.to_key' => 'required|string',
            // A global or same-organization transformation only — a global project type can
            // only use global ones, so no organization's workflow ever runs another's template.
            'workflow.*.ai_template_id' => [
                'nullable',
                Rule::exists('ai_templates', 'id')->where(
                    fn ($query) => $query->whereNull('organization_id')->when($orgId, fn ($query) => $query->orWhere('organization_id', $orgId))
                ),
            ],

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
        // Lifecycle steps are moving to the independent LifecycleTemplate model (see
        // LifecycleTemplate/lifecycle_template_id), but this editor is still the only UI for them
        // today, so keep the matching template (same name/org as this protocol, per the original
        // 1:1 clone) in sync until a dedicated LifecycleTemplate editor exists.
        $lifecycleTemplate = LifecycleTemplate::firstOrCreate([
            'organization_id' => $projectType->organization_id,
            'name' => $projectType->name,
        ]);

        $stepIds = collect($steps)->pluck('id')->filter()->all();
        $projectType->lifecycleSteps()->whereNotIn('id', $stepIds)->delete();

        foreach ($steps as $step) {
            if (! empty($step['id'])) {
                $projectType->lifecycleSteps()->where('id', $step['id'])->update([
                    'lifecycle_template_id' => $lifecycleTemplate->id,
                    'order' => $step['order'],
                    'label' => $step['label'],
                    'description' => $step['description'] ?? null,
                    'color' => $step['color'] ?? null,
                ]);
            } else {
                $projectType->lifecycleSteps()->create([
                    'lifecycle_template_id' => $lifecycleTemplate->id,
                    'order' => $step['order'],
                    'label' => $step['label'],
                    'description' => $step['description'] ?? null,
                    'color' => $step['color'] ?? null,
                ]);
            }
        }
    }
}
