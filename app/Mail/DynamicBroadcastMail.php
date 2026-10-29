<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DynamicBroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $emailSubject;
    public string $renderedHtml;

    public function __construct(string $emailSubject, string $renderedHtml)
    {
        $this->emailSubject = $emailSubject;
        $this->renderedHtml = $renderedHtml;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.broadcast_layout',
            with: [
                'bodyContent' => $this->renderedHtml,
            ]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
