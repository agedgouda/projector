<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * UploadedFile::fake()->create() reports a fake size/mime but leaves the underlying temp
 * file genuinely empty, which MediaLibrary's real content-based mime detection rejects. A
 * minimal valid WAV header gives it real bytes to actually detect as audio.
 */
function fakeWavFile(string $name = 'meeting.wav'): \Illuminate\Http\UploadedFile
{
    $dataSize = 100;
    $header = 'RIFF'.pack('V', 36 + $dataSize).'WAVE'.'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
        .pack('V', 8000).pack('V', 8000).pack('v', 1).pack('v', 8).'data'.pack('V', $dataSize)
        .str_repeat("\x00", $dataSize);

    return \Illuminate\Http\UploadedFile::fake()->createWithContent($name, $header);
}
