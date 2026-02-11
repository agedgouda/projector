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

        return inertia('AiTemplates/Index', [
            'templates' => AiTemplate::orderBy('name')->get()
        ]);
    }

    public function show(AiTemplate $aiTemplate)
{
    return Inertia::render('AiTemplates/Show', [
        'aiTemplate' => [
            'id' => $aiTemplate->id,
            'name' => $aiTemplate->name,
            'system_prompt' => $aiTemplate->system_prompt,
            'user_prompt' => $aiTemplate->user_prompt,
        ]
    ]);
}

    public function create()
    {
        return inertia('AiTemplates/Manage');
    }

    public function edit(AiTemplate $aiTemplate)
    {

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

        // Capture the new record
        $template = AiTemplate::create($validated);

        // Redirect to the edit page of the new record
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

        return back()->with('success', 'AI Template updated.');
    }

    public function destroy(AiTemplate $aiTemplate)
    {
        // The Policy now handles the "isUsed" check
        Gate::authorize('delete', $aiTemplate);

        $aiTemplate->delete();

        return back()->with('success', 'AI Template deleted.');
    }
}
