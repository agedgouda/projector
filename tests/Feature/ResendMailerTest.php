<?php

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;

it('builds the resend transport from the RESEND_API_KEY config', function () {
    config(['services.resend.key' => 're_test_key']);

    expect(Mail::mailer('resend')->getSymfonyTransport())->toBeInstanceOf(ResendTransport::class)
        ->and((string) Mail::mailer('resend')->getSymfonyTransport())->toBe('resend');
});
