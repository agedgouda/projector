<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Client;
use App\Models\ProjectType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        // 1. Authorize access to the list
        Gate::authorize('viewAny', Project::class);

        // 2. Fetch scoped projects using your Model Scope + Custom Collection Summary
        $projects = Project::visibleTo($request->user())
            ->latest()
            ->get()
            ->withSummary();

        // 3. Security: If they aren't a Super-Admin and have no clients assigned,
        // they shouldn't even see an empty index.
        if (!$request->user()->hasRole('super-admin') && $request->user()->clients()->doesntExist()) {
            abort(404);
        }

        return inertia('Projects/Index', [
            'projects' => $projects,
            'clients' => Client::visibleTo($request->user())->get(),
            'projectTypes' => ProjectType::all(),
        ]);
    }

    public function show(Project $project)
    {
        // 1. Authorize via ProjectPolicy@view
        Gate::authorize('view', $project);

        return inertia('Projects/Show', [
            'project' => $project->loadFullPipeline(),
            'projectTypes' => ProjectType::all(),
        ]);
    }

    public function store(Request $request)
    {
        // 1. Authorize via ProjectPolicy@create
        Gate::authorize('create', Project::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'client_id' => 'required|exists:clients,id',
            'project_type_id' => 'required|exists:project_types,id',
        ]);

        Project::create($validated);

        return redirect()->back()->with('success', 'Project created successfully.');
    }

    public function update(Request $request, Project $project)
    {
        // 1. Authorize via ProjectPolicy@update
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_type_id' => 'required|exists:project_types,id',
            'client_id' => 'sometimes|required|exists:clients,id',
            'status' => 'sometimes|string',
        ]);

        $project->update($validated);

        return redirect()->back()->with('success', 'Project updated successfully.');
    }

    public function destroy(Project $project)
    {
        // 1. Authorize via ProjectPolicy@delete
        Gate::authorize('delete', $project);

        $project->delete();
        $message = 'Project was successfully deleted.';

        if (request()->has('redirect_to')) {
            return redirect(request()->get('redirect_to'))->with('success', $message);
        }

        return redirect()->back()->with('success', $message);
    }

    public function storeDocument(Request $request, Project $project)
    {
        // 1. Reuse update authorization or specific document logic
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|string',
            'content' => 'required|string',
        ]);

        $project->documents()->create($validated);

        return back()->with('success', 'Document added and indexed.');
    }
}
