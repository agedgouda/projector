<?php

use App\Models\Faq;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds one Dropbox FAQ entry per setup and usage topic, in order, with keywords', function () {
    $faqs = Faq::where('category', 'Dropbox')->orderBy('order')->get();

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

    expect($faqs->where('category', 'Dropbox'))->toHaveCount(9);
});
