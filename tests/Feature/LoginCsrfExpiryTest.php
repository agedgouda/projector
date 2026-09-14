<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('flashes a status message the login page can display when a 419 is thrown', function () {
    Route::get('/__test-419', function () {
        throw new HttpException(419, 'Page Expired');
    });

    $response = $this->get('/__test-419');

    $response->assertRedirect(route('login'));
    expect(session('status'))->toBe('Your session expired. Please log in again.');
});
