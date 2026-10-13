<?php

use App\Models\Faq;
use Inertia\Testing\AssertableInertia;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('shows the FAQ to visitors who aren\'t logged in', function () {
    $this->get(route('faq.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Faq/Index')
            ->has('faqs', Faq::count()));
});

it('still requires a super-admin to change the FAQ', function () {
    $faq = Faq::firstOrFail();

    $this->post(route('faq.store'), ['category' => 'X', 'question' => 'Q?', 'answer' => 'A'])
        ->assertRedirectContains('/login');
    $this->put(route('faq.update', $faq), ['category' => 'X', 'question' => 'Q?', 'answer' => 'A'])
        ->assertRedirectContains('/login');
    $this->delete(route('faq.destroy', $faq))
        ->assertRedirectContains('/login');

    expect($faq->fresh())->not->toBeNull()
        ->and(Faq::where('question', 'Q?')->exists())->toBeFalse();
});
