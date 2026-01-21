<?php

namespace App\Http\Controllers;

use App\Models\AiTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AiTemplateController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', AiTemplate::class);

        return inertia('AiTemplates/Index', [
            'templates' => AiTemplate::orderBy('name')->get()
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

        AiTemplate::create($validated);

        return back()->with('success', 'AI Template created.');
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
