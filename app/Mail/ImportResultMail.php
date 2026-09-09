<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email fallback App\Services\Import\ImportNotifier uses when the recipient has no linked
 * Slack identity to DM instead — the plain-text $resultMessage is the exact same string a Slack
 * channel reply or DM would have shown, just delivered a different way.
 *
 * Deliberately not named $message: Mailable already has its own internal $message property (the
 * Illuminate\Mail\Message being built during send()) — a same-named public property here would
 * shadow it and silently break rendering.
 */
class ImportResultMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $resultMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Projector import update',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.import-result',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
