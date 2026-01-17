<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class TaskController extends Controller
{
    /**
     * Store a newly created task in storage.
     */
    public function store(Request $request)
    {

        $validated = $request->validate([
            'project_id'  => ['required', 'uuid', 'exists:projects,id'],
            'document_id' => ['nullable', 'uuid', 'exists:documents,id'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['required', 'string', 'in:todo,in_progress,done,backlog'],
            'priority'    => ['required', 'string', 'in:low,medium,high'],
            'due_at'      => ['nullable', 'date'],
        ]);

        Task::create($validated);

        // Inertia will automatically pick this up and trigger the 'onSuccess'
        // in your Vue component, which then triggers the Sonner toast.
        return back()->with('success', 'Task created successfully.');
    }

    /**
     * Update the specified task in storage.
     */
    public function update(Request $request, Task $task)
    {

        $validated = $request->validate([
            'title'       => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status'      => ['sometimes', 'required', 'string'],
            'priority'    => ['sometimes', 'required', 'string'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'due_at'      => ['nullable', 'date'],
        ]);

        $task->update($validated);

        return back()->with('success', 'Task updated successfully.');
    }
}
