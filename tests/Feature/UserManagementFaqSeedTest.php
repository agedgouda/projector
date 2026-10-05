<?php

use App\Models\Faq;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('seeds the User Management and Your Account FAQ entries, in order, with keywords', function (string $category, int $count) {
    $faqs = Faq::where('category', $category)->orderBy('order')->get();

    expect($faqs)->toHaveCount($count);

    foreach ($faqs as $faq) {
        expect($faq->question)->not()->toBeEmpty()
            ->and($faq->answer)->not()->toBeEmpty()
            ->and($faq->keywords)->not()->toBeEmpty();
    }

    expect($faqs->pluck('order')->all())->toBe($faqs->pluck('order')->sort()->values()->all());
})->with([
    'user management' => ['User Management', 6],
    'your account' => ['Your Account', 9],
]);

it('covers inviting users and having a new user update their information', function () {
    $questions = Faq::whereIn('category', ['User Management', 'Your Account'])->pluck('question');

    expect($questions)
        ->toContain('How do I invite a new person to my organization?')
        ->toContain('I received an invitation email. How do I join?')
        ->toContain('How do I update my name, email address, or timezone?');
});

it('lists the account and user management categories before the integration categories on the FAQ page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('faq.index'));

    $response->assertOk();

    /** @var list<array{category: string}> $faqs */
    $faqs = $response->viewData('page')['props']['faqs'];
    $categoryOrder = array_values(array_unique(array_column($faqs, 'category')));

    expect(array_slice($categoryOrder, 0, 2))->toBe(['User Management', 'Your Account']);
});
