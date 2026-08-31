<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Document;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    /**
     * Comments are only rendered inside pages/sheets the user already has access to, and
     * form.post()'s `back()` response can't hand the new comment back to a component whose
     * state isn't a live Inertia prop (e.g. the report table's locally-fetched rows) — so
     * DocumentDetailSheet.vue's Reports usage re-fetches the fresh list from here after a
     * post/delete instead of relying on Inertia's page-prop refresh.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:task,document',
            'id' => 'required',
        ]);

        $modelMap = [
            'task' => Task::class,
            'document' => Document::class,
        ];

        $modelClass = $modelMap[$validated['type']];
        $commentable = $modelClass::findOrFail($validated['id']);

        Gate::authorize('comment', $commentable);

        return response()->json([
            'comments' => $commentable->comments()->with('user')->get()->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'user_id' => $comment->user_id,
                'body' => $comment->body,
                'commentable_type' => $comment->commentable_type,
                'commentable_id' => $comment->commentable_id,
                'created_at' => $comment->created_at,
                'updated_at' => $comment->updated_at,
                'user' => $comment->user ? [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                    'first_name' => $comment->user->first_name,
                    'last_name' => $comment->user->last_name,
                ] : null,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'body' => 'required|string',
            'type' => 'required|in:task,document', // Validates against our two types
            'id' => 'required',
        ]);

        // Map the simple 'type' from Vue to the actual Model class
        $modelMap = [
            'task' => Task::class,
            'document' => Document::class,
        ];

        $modelClass = $modelMap[$validated['type']];
        $commentable = $modelClass::findOrFail($validated['id']);

        Gate::authorize('comment', $commentable);

        $commentable->comments()->create([
            'body' => $validated['body'],
            'user_id' => $request->user()->id,
        ]);

        return back();
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        Gate::authorize('update', $comment);

        $validated = $request->validate([
            'body' => 'required|string',
        ]);

        $comment->update($validated);

        return back();
    }

    public function destroy(Comment $comment)
    {
        Gate::authorize('delete', $comment);

        $comment->delete();

        // Return back to the project page.
        // Inertia will automatically refresh the project/task props.
        return back();
    }
}
