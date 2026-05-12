<?php

namespace App\Http\Controllers;

use App\Models\AiTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class AiTemplateController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', AiTemplate::class);

        $user = auth()->user();

        $query = AiTemplate::orderBy('type')->orderBy('name');

        if (! $user->hasRole('super-admin')) {
            $orgId = getPermissionsTeamId();
            $query->where(function ($q) use ($orgId) {
                $q->whereNull('organization_id')
                    ->orWhere('organization_id', $orgId);
            });
        }

        $templates = $query->get()->map(function (AiTemplate $t) use ($user) {
            return [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type,
                'organization_id' => $t->organization_id,
                'system_prompt' => $t->system_prompt,
                'user_prompt' => $t->user_prompt,
                'can_edit' => $user->can('update', $t),
            ];
        });

        return inertia('AiTemplates/Index', [
            'templates' => $templates,
        ]);
    }

    public function show(AiTemplate $aiTemplate)
    {
        $user = auth()->user();

        return Inertia::render('AiTemplates/Show', [
            'aiTemplate' => [
                'id' => $aiTemplate->id,
                'name' => $aiTemplate->name,
                'system_prompt' => $aiTemplate->system_prompt,
                'user_prompt' => $aiTemplate->user_prompt,
            ],
            'canEdit' => $user->can('update', $aiTemplate),
        ]);
    }

    public function create()
    {
        Gate::authorize('create', AiTemplate::class);

        return inertia('AiTemplates/Manage');
    }

    public function edit(AiTemplate $aiTemplate)
    {
        Gate::authorize('update', $aiTemplate);

        return Inertia::render('AiTemplates/Manage', [
            'aiTemplate' => $aiTemplate,
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', AiTemplate::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'system_prompt' => 'required|string',
            'user_prompt' => 'required|string',
        ]);

        $user = auth()->user();
        $validated['organization_id'] = $user->hasRole('super-admin')
            ? null
            : getPermissionsTeamId();

        $template = AiTemplate::create($validated);

        return redirect()->to(route('ai-templates.edit', $template->id))
            ->with('success', 'AI Template created.');
    }

    public function update(Request $request, AiTemplate $aiTemplate)
    {
        Gate::authorize('update', $aiTemplate);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'system_prompt' => 'required|string',
            'user_prompt' => 'required|string',
        ]);

        $aiTemplate->update($validated);

        return redirect()->route('ai-templates.show', $aiTemplate)
            ->with('success', 'AI Template updated.');
    }

    public function destroy(AiTemplate $aiTemplate)
    {
        Gate::authorize('delete', $aiTemplate);

        $aiTemplate->delete();

        return back()->with('success', 'AI Template deleted.');
    }

    public function duplicate(AiTemplate $aiTemplate)
    {
        Gate::authorize('duplicate', AiTemplate::class);

        $user = auth()->user();

        $copy = $aiTemplate->replicate(['id', 'created_at', 'updated_at']);
        $copy->name = $aiTemplate->name.' (Copy)';
        $copy->organization_id = $user->hasRole('super-admin') ? null : getPermissionsTeamId();
        $copy->save();

        return redirect()->route('ai-templates.edit', $copy->id)
            ->with('success', 'AI Template copied.');
    }
}
