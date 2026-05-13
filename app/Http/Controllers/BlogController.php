<?php

namespace App\Http\Controllers;

use App\Models\BlogEntry;
use Inertia\Inertia;
use Inertia\Response;

class BlogController extends Controller
{
    public function index(): Response
    {
        $posts = BlogEntry::query()
            ->where('published', true)
            ->orderByDesc('date')
            ->get(['id', 'slug', 'date', 'data']);

        return Inertia::render('Blog/Index', [
            'posts' => $posts->map(fn (BlogEntry $post) => [
                'id' => $post->id,
                'slug' => $post->slug,
                'title' => $post->title,
                'date' => $post->parsed_date?->toDateString(),
            ]),
        ]);
    }

    public function show(string $slug): Response
    {
        $post = BlogEntry::query()
            ->where('published', true)
            ->where('slug', $slug)
            ->firstOrFail(['id', 'slug', 'date', 'data']);

        return Inertia::render('Blog/Show', [
            'post' => [
                'id' => $post->id,
                'slug' => $post->slug,
                'title' => $post->title,
                'content' => $post->content,
                'date' => $post->parsed_date?->toDateString(),
            ],
        ]);
    }
}
