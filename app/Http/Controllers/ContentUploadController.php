<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContentUploadController extends Controller
{
    /**
     * Extensions that could execute as script/HTML if opened directly — blocked because
     * these files are served straight from the public disk as static assets, not because
     * of the file type itself (see resources/js/composables/useDocumentEditor.ts, which
     * accepts uploads of any other type into document/comment rich text bodies).
     *
     * @var list<string>
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar', 'exe', 'sh',
        'bat', 'cmd', 'htm', 'html', 'svg', 'js', 'mjs', 'jsp', 'asp', 'aspx', 'cgi',
    ];

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $extension = Str::lower($file->getClientOriginalExtension());

        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => 'This file type is not allowed.',
            ]);
        }

        $path = $file->store("content-uploads/{$project->id}", 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded file.');
        }

        return response()->json([
            'url' => Storage::disk('public')->url($path),
            'name' => $file->getClientOriginalName(),
        ]);
    }
}
