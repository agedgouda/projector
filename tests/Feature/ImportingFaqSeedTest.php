<?php

use App\Models\Faq;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds the Importing FAQ entries, in order, with keywords', function () {
    $faqs = Faq::where('category', 'Importing')->orderBy('order')->get();

    expect($faqs)->toHaveCount(8);

    foreach ($faqs as $faq) {
        expect($faq->question)->not()->toBeEmpty()
            ->and($faq->answer)->not()->toBeEmpty()
            ->and($faq->keywords)->not()->toBeEmpty();
    }

    expect($faqs->pluck('order')->all())->toBe($faqs->pluck('order')->sort()->values()->all());
});

it('points the overview at the Slack and Dropbox import entries that actually exist', function () {
    $overview = Faq::where('category', 'Importing')->where('question', 'What are all the ways to import into Projector?')->firstOrFail();

    $referenced = [
        'How do I import a task or event list by uploading a file in Slack?',
        'How do I import files by dropping them in a bound Dropbox folder?',
    ];

    foreach ($referenced as $question) {
        expect($overview->answer)->toContain($question)
            ->and(Faq::where('question', $question)->exists())->toBeTrue();
    }
});

it('lists Importing, then Transcripts & Meeting Notes, after Projects and before the integration categories on the FAQ page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('faq.index'));

    $response->assertOk();

    /** @var list<array{category: string}> $faqs */
    $faqs = $response->viewData('page')['props']['faqs'];
    $categoryOrder = array_values(array_unique(array_column($faqs, 'category')));

    expect($categoryOrder)->toBe(['User Management', 'Your Account', 'Projects', 'Importing', 'Transcripts & Meeting Notes', 'Reports', 'Tasks & Events', 'Documents', 'Transformations', 'Status Meetings', 'Dashboard & Help', 'Organization Settings', 'Slack', 'Dropbox']);
});
