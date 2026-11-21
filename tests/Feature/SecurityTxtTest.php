<?php

use Illuminate\Support\Carbon;

function securityTxtFields(): array
{
    $fields = [];

    foreach (file(public_path('.well-known/security.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$name, $value] = array_map(trim(...), explode(':', $line, 2));
        $fields[$name] = $value;
    }

    return $fields;
}

it('publishes a security.txt with the required fields', function () {
    $fields = securityTxtFields();

    expect($fields)->toHaveKeys(['Contact', 'Expires', 'Canonical'])
        ->and($fields['Contact'])->toStartWith('mailto:')
        ->and($fields['Canonical'])->toBe('https://projecthq.app/.well-known/security.txt');
});

it('has a security.txt that has not expired and expires within a year', function () {
    $expires = Carbon::parse(securityTxtFields()['Expires']);

    expect($expires->isFuture())->toBeTrue()
        ->and($expires->lessThanOrEqualTo(now()->addYear()->addDay()))->toBeTrue();
});
