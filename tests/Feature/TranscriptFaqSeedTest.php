<?php

use App\Models\Faq;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds the Transcripts & Meeting Notes FAQ entries, in order, with keywords', function () {
    $faqs = Faq::where('category', 'Transcripts & Meeting Notes')->orderBy('order')->get();

    expect($faqs)->toHaveCount(6);

    foreach ($faqs as $faq) {
        expect($faq->question)->not()->toBeEmpty()
            ->and($faq->answer)->not()->toBeEmpty()
            ->and($faq->keywords)->not()->toBeEmpty();
    }

    expect($faqs->pluck('order')->all())->toBe($faqs->pluck('order')->sort()->values()->all());
});

it('points to a Status Meetings entry that actually exists', function () {
    $ways = Faq::where('question', 'What are the ways to get a meeting transcript into a project?')->firstOrFail();
    $referenced = 'How do I import notes from a meeting that covered several projects?';

    expect($ways->answer)->toContain($referenced)
        ->and(Faq::where('question', $referenced)->exists())->toBeTrue();
});

it('explains both the Add and Replace options for Additional Information', function () {
    $faq = Faq::where('question', 'What does the "Additional Information" box do when I import a transcript?')->firstOrFail();

    expect($faq->answer)
        ->toContain('Add to the standard instructions')
        ->toContain('Replace the standard instructions');
});

it('explains what to do when a recording doesn\'t get transcribed, as the last transcript question', function () {
    $faqs = Faq::where('category', 'Transcripts & Meeting Notes')->orderBy('order')->get();

    expect($faqs->last()->question)->toBe('What if a recording doesn\'t get transcribed?')
        ->and($faqs->last()->answer)
        ->toContain('Try Again')
        ->toContain('Download Audio')
        ->toContain('Recovered recording')
        ->toContain('Org Admins and Project Leads');
});
