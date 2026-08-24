<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const COLOR_PALETTE = ['slate', 'red', 'amber', 'emerald', 'blue', 'purple', 'pink', 'orange', 'indigo', 'teal'];

    public function index(Project $project): JsonResponse
    {
        return response()->json($project->familyCategories()->values());
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $root = $project->familyRoot();
        Gate::authorize('manageCategories', $root);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->where('project_id', $root->id)],
            'color' => ['required', 'string', Rule::in(self::COLOR_PALETTE)],
        ]);

        $root->categories()->create($validated);

        return back()->with('success', 'Tag added.');
    }

    public function update(Request $request, Project $project, Category $category): RedirectResponse
    {
        $root = $project->familyRoot();
        Gate::authorize('manageCategories', $root);
        abort_if($category->project_id !== $root->id, 404);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('categories', 'name')->where('project_id', $root->id)->ignore($category->id)],
            'color' => ['sometimes', 'required', 'string', Rule::in(self::COLOR_PALETTE)],
        ]);

        $category->update($validated);

        return back()->with('success', 'Tag updated.');
    }

    public function destroy(Project $project, Category $category): RedirectResponse
    {
        $root = $project->familyRoot();
        Gate::authorize('manageCategories', $root);
        abort_if($category->project_id !== $root->id, 404);

        $category->delete();

        return back()->with('success', 'Tag deleted.');
    }
}
