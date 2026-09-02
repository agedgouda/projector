<?php

use App\Models\DocumentTypeDefinition;

it('always reads label as Title Case, regardless of how it was stored', function (string $stored, string $expected) {
    $definition = new DocumentTypeDefinition(['label' => $stored]);

    expect($definition->label)->toBe($expected);
})->with([
    'lowercase' => ['meeting notes', 'Meeting Notes'],
    'uppercase' => ['MEETING NOTES', 'Meeting Notes'],
    'already title case' => ['Meeting Notes', 'Meeting Notes'],
    'mixed case' => ['mEETing NoTES', 'Meeting Notes'],
]);
