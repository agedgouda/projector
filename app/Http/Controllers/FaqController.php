<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FaqController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Faq/Index', [
            'faqs' => Faq::orderBy('order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|max:255',
            'question' => 'required|string',
            'answer' => 'required|string',
            'keywords' => 'nullable|string|max:500',
            'order' => 'integer|min:0',
        ]);

        Faq::create($validated);

        return back()->with('success', 'FAQ item created.');
    }

    public function update(Request $request, Faq $faq): RedirectResponse
    {
        $validated = $request->validate([
            'category' => 'required|string|max:255',
            'question' => 'required|string',
            'answer' => 'required|string',
            'keywords' => 'nullable|string|max:500',
            'order' => 'integer|min:0',
        ]);

        $faq->update($validated);

        return back()->with('success', 'FAQ item updated.');
    }

    public function destroy(Faq $faq): RedirectResponse
    {
        $faq->delete();

        return back()->with('success', 'FAQ item deleted.');
    }
}
