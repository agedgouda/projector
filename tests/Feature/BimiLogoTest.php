<?php

it('ships a bimi logo in svg tiny ps format', function () {
    $path = public_path('bimi-logo.svg');
    $svg = simplexml_load_file($path);

    expect($svg)->not->toBeFalse()
        ->and(filesize($path))->toBeLessThan(32 * 1024)
        ->and((string) $svg['version'])->toBe('1.2')
        ->and((string) $svg['baseProfile'])->toBe('tiny-ps')
        ->and((string) $svg['viewBox'])->toBe('0 0 500 500')
        ->and(isset($svg['x']) || isset($svg['y']))->toBeFalse()
        ->and((string) $svg->title)->toBe('Projector');

    expect(file_get_contents($path))
        ->not->toContain('<style')
        ->not->toContain('<script')
        ->not->toContain('class=')
        ->not->toContain('xlink:href')
        ->not->toContain('<image');
});
