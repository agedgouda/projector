<?php

use App\Models\Faq;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds the Projects FAQ entries, in order, with keywords', function () {
    $faqs = Faq::where('category', 'Projects')->orderBy('order')->get();

    expect($faqs)->toHaveCount(11);

    foreach ($faqs as $faq) {
        expect($faq->question)->not()->toBeEmpty()
            ->and($faq->answer)->not()->toBeEmpty()
            ->and($faq->keywords)->not()->toBeEmpty();
    }

    expect($faqs->pluck('order')->all())->toBe($faqs->pluck('order')->sort()->values()->all());
});

it('covers creating, editing, deactivating, and deleting projects', function () {
    $questions = Faq::where('category', 'Projects')->pluck('question');

    expect($questions)
        ->toContain('How do I create a new project?')
        ->toContain('How do I rename a project or change its description or logo?')
        ->toContain('How do I deactivate a finished project, or bring one back?')
        ->toContain('How do I delete a project?')
        ->toContain('How do I add a project to my Favorites?');
});

it('lists Projects right after the account categories on the FAQ page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('faq.index'));

    $response->assertOk();

    /** @var list<array{category: string}> $faqs */
    $faqs = $response->viewData('page')['props']['faqs'];
    $categoryOrder = array_values(array_unique(array_column($faqs, 'category')));

    expect(array_slice($categoryOrder, 0, 3))->toBe(['User Management', 'Your Account', 'Projects']);
});
