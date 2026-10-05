<?php

use App\Models\Faq;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds the Reports FAQ entries, in order, with keywords, and moves the Slack /report entry in last', function () {
    $faqs = Faq::where('category', 'Reports')->orderBy('order')->get();

    expect($faqs)->toHaveCount(6)
        ->and($faqs->last()->question)->toBe('How do I get a task report or event calendar from Slack?');

    foreach ($faqs as $faq) {
        expect($faq->question)->not()->toBeEmpty()
            ->and($faq->answer)->not()->toBeEmpty()
            ->and($faq->keywords)->not()->toBeEmpty();
    }

    expect($faqs->pluck('order')->all())->toBe($faqs->pluck('order')->sort()->values()->all());
});

it('no longer lists the /report entry under Slack', function () {
    expect(Faq::where('category', 'Slack')->where('question', 'How do I get a task report or event calendar from Slack?')->exists())->toBeFalse();
});

it('covers running and downloading reports on the website and from Slack', function () {
    $questions = Faq::where('category', 'Reports')->pluck('question');

    expect($questions)
        ->toContain('How do I run a task report on the website?')
        ->toContain('How do I download a task report?')
        ->toContain('How do I download a project\'s calendar?')
        ->toContain('How do I get a task report or event calendar from Slack?');
});
