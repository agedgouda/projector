<?php

use App\Services\DocumentFileExtractorService;

it('extracts body-only html from a real docx file', function () {
    $phpWord = new \PhpOffice\PhpWord\PhpWord;
    $section = $phpWord->addSection();
    $section->addText('First paragraph.');
    $section->addText('Second paragraph.');

    $tmpPath = tempnam(sys_get_temp_dir(), 'docx').'.docx';
    (new \PhpOffice\PhpWord\Writer\Word2007($phpWord))->save($tmpPath);

    $html = (new DocumentFileExtractorService)->extractDocxHtml($tmpPath);

    unlink($tmpPath);

    expect($html)->toContain('First paragraph.')
        ->and($html)->toContain('Second paragraph.')
        ->and($html)->not->toContain('<html')
        ->and($html)->not->toContain('<head');
});

it('wraps each line of a txt file in its own paragraph', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($tmpPath, "First line.\nSecond line.\nThird line.");

    $html = (new DocumentFileExtractorService)->extractTxtHtml($tmpPath);

    unlink($tmpPath);

    expect($html)->toBe('<p>First line.</p><p>Second line.</p><p>Third line.</p>');
});

it('html-escapes special characters in a txt file', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($tmpPath, '<script>alert(1)</script>');

    $html = (new DocumentFileExtractorService)->extractTxtHtml($tmpPath);

    unlink($tmpPath);

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('renders a single blank line for an empty txt file', function () {
    $tmpPath = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($tmpPath, '');

    $html = (new DocumentFileExtractorService)->extractTxtHtml($tmpPath);

    unlink($tmpPath);

    expect($html)->toBe('<p>&nbsp;</p>');
});
