<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class HrisUpdateMail extends Mailable
{
    public function __construct(
        public readonly string $title,
        public readonly string $bodyText,
        public readonly string $actionUrl,
        public readonly string $frontendUrl,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[HRIS] '.$this->title,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.hris-update',
        );
    }
}
