<?php

namespace App\Http\Controllers;

use App\Models\AiTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AiTemplateController extends Controller
{
    /**
     * Display a listing of the templates.
     */
    public function index()
    {
        return Inertia::render('AiTemplates/Index', [
            'templates' => AiTemplate::orderBy('name')->get()
        ]);
    }

    /**
     * Store a newly created template in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'system_prompt' => 'required|string',
            'user_prompt' => 'required|string',
        ]);

        AiTemplate::create($validated);

        return redirect()->back()->with('success', 'AI Template created successfully.');
    }

    /**
     * Update the specified template in storage.
     */
    public function update(Request $request, AiTemplate $aiTemplate)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'system_prompt' => 'required|string',
            'user_prompt' => 'required|string',
        ]);

        $aiTemplate->update($validated);

        return redirect()->back()->with('success', 'AI Template updated successfully.');
    }

    /**
     * Remove the specified template from storage.
     */
    public function destroy(AiTemplate $aiTemplate)
    {
        // Check if any Project Types are using this template before deleting
        // This prevents breaking the workflow JSON logic
        $isUsed = \App\Models\ProjectType::where('workflow', '@>', json_encode([['ai_template_id' => $aiTemplate->id]]))->exists();

        if ($isUsed) {
            return redirect()->back()->with('error', 'Cannot delete template: It is currently used in a Project Type workflow.');
        }

        $aiTemplate->delete();

        return redirect()->back()->with('success', 'AI Template deleted successfully.');
    }
}
