<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Document;
use App\Models\Task;

use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'body' => 'required|string',
            'type' => 'required|in:task,document', // Validates against our two types
            'id'   => 'required',
        ]);

        // Map the simple 'type' from Vue to the actual Model class
        $modelMap = [
            'task' => Task::class,
            'document' => Document::class,
        ];

        $modelClass = $modelMap[$validated['type']];
        $commentable = $modelClass::findOrFail($validated['id']);

        $commentable->comments()->create([
            'body'    => $validated['body'],
            'user_id' => $request->user()->id,
        ]);


        return back();
    }

    public function destroy(Comment $comment)
    {
        // Authorization: Ensure only the author can delete
        if (auth()->id() !== $comment->user_id) {
            abort(403, 'You are not authorized to delete this comment.');
        }

        $comment->delete();

        // Return back to the project page.
        // Inertia will automatically refresh the project/task props.
        return back();
    }
}
