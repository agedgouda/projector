<?php

use App\Models\Faq;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds one Dropbox FAQ entry per usage topic, in order, with keywords', function () {
    $faqs = Faq::where('category', 'Dropbox')->orderBy('order')->get();

    expect($faqs)->toHaveCount(6);

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

    expect($faqs->where('category', 'Dropbox'))->toHaveCount(6);
});

it('has no app setup or server configuration content in the Slack or Dropbox entries', function () {
    $faqs = Faq::whereIn('category', ['Slack', 'Dropbox'])->get();

    foreach ($faqs as $faq) {
        expect($faq->answer)
            ->not()->toContain('.env')
            ->not()->toContain('developers/apps')
            ->not()->toContain('Herd')
            ->not()->toContain('CLIENT_SECRET');
    }
});
