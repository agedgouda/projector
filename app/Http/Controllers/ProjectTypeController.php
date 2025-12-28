<?php

namespace App\Http\Controllers;

use App\Models\ProjectType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProjectTypeController extends Controller
{
    public function index()
    {
        return Inertia::render('ProjectTypes/Index', [
            'projectTypes' => ProjectType::orderBy('name')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:project_types,name|max:255',
            'icon' => 'nullable|string|max:100',
        ]);

        ProjectType::create($validated);

        return redirect()->back()->with('message', 'Type created.');
    }

    public function update(Request $request, ProjectType $projectType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:project_types,name,' . $projectType->id,
            'icon' => 'nullable|string|max:100',
        ]);

        $projectType->update($validated);

        return redirect()->back();
    }

    public function destroy(ProjectType $projectType)
    {
        if ($projectType->projects()->exists()) {
            return redirect()->back()->withErrors(['delete' => 'Type is in use.']);
        }

        $projectType->delete();

        return redirect()->back();
    }
}
