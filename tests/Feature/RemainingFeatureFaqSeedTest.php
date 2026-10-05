<?php

use App\Models\Faq;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds each new category, in order, with keywords', function (string $category, int $count) {
    $faqs = Faq::where('category', $category)->orderBy('order')->get();

    expect($faqs)->toHaveCount($count);

    foreach ($faqs as $faq) {
        expect($faq->question)->not()->toBeEmpty()
            ->and($faq->answer)->not()->toBeEmpty()
            ->and($faq->keywords)->not()->toBeEmpty();
    }

    expect($faqs->pluck('order')->all())->toBe($faqs->pluck('order')->sort()->values()->all());
})->with([
    'tasks & events' => ['Tasks & Events', 9],
    'documents' => ['Documents', 5],
    'transformations' => ['Transformations', 5],
    'status meetings' => ['Status Meetings', 3],
    'dashboard & help' => ['Dashboard & Help', 2],
    'organization settings' => ['Organization Settings', 5],
]);

it('adds the Google account and new organization entries to Your Account, after the existing ones', function () {
    $questions = Faq::where('category', 'Your Account')->orderBy('order')->pluck('question')->all();

    expect($questions)->toHaveCount(9)
        ->and(array_slice($questions, -2))->toBe([
            'How do I connect my Google account?',
            'How do I set up a new organization of my own?',
        ]);
});

it('adds phone recording to Transcripts & Meeting Notes, including the list of ways in', function () {
    expect(Faq::where('category', 'Transcripts & Meeting Notes')->where('question', 'Can I record a meeting on my phone?')->exists())->toBeTrue();

    $ways = Faq::where('question', 'What are the ways to get a meeting transcript into a project?')->firstOrFail();

    expect($ways->answer)->toContain('Record on your phone');
});

it('only references FAQ questions that exist', function () {
    $answers = Faq::whereIn('category', ['Tasks & Events', 'Documents', 'Transformations', 'Status Meetings', 'Dashboard & Help', 'Organization Settings', 'Your Account'])->pluck('answer');
    $questions = Faq::pluck('question')->all();

    foreach ($answers as $answer) {
        preg_match_all('/see "([^"]+\?)"/i', str_replace('\\"', '"', $answer), $matches);

        foreach ($matches[1] as $referenced) {
            expect($questions)->toContain($referenced);
        }
    }
});

it('lists every category in order on the FAQ page', function () {
    $user = User::factory()->create();

    $faqs = $this->actingAs($user)->get(route('faq.index'))->viewData('page')['props']['faqs'];
    $categoryOrder = array_values(array_unique(array_column($faqs, 'category')));

    expect($categoryOrder)->toBe([
        'User Management', 'Your Account', 'Projects', 'Importing', 'Transcripts & Meeting Notes', 'Reports',
        'Tasks & Events', 'Documents', 'Transformations', 'Status Meetings', 'Dashboard & Help', 'Organization Settings',
        'Slack', 'Dropbox',
    ]);
});
