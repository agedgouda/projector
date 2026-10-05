<?php

use App\Models\Faq;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds one Slack FAQ entry per usage topic, in order, with keywords', function () {
    $faqs = Faq::where('category', 'Slack')->orderBy('order')->get();

    expect($faqs)->toHaveCount(9);

    foreach ($faqs as $faq) {
        expect($faq->question)->not->toBeEmpty()
            ->and($faq->answer)->not->toBeEmpty()
            ->and($faq->keywords)->not->toBeEmpty();
    }

    expect($faqs->pluck('order')->all())->toBe($faqs->pluck('order')->sort()->values()->all());
});

it('is visible on the FAQ page', function () {
    $user = \App\Models\User::factory()->create();

    $response = $this->actingAs($user)->get(route('faq.index'));

    $response->assertOk();
    $faqs = collect($response->viewData('page')['props']['faqs']);

    expect($faqs->where('category', 'Slack'))->toHaveCount(9);
});

it('documents the /report command under Reports', function () {
    $faq = Faq::where('category', 'Reports')->where('question', 'How do I get a task report or event calendar from Slack?')->firstOrFail();

    expect($faq->answer)->toContain('/report');
});

it('describes the current Connect button on the Configuration tab', function () {
    $answers = Faq::whereIn('category', ['Slack', 'Dropbox'])->pluck('answer')->implode("\n");

    expect($answers)
        ->not()->toContain('Connect Slack Workspace')
        ->not()->toContain('click "Connect Dropbox"');
});
