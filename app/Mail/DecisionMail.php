<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DecisionMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $greeting,
        public string $body,
        public ?string $ctaUrl = null,
        public ?string $ctaLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[SixAgo] '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.decision');
    }
}
