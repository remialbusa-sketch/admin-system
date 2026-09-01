<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExecutiveDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary  SendExecutiveDigest::buildPayload() data
     */
    public function __construct(
        public array $summary,
        public string $scope,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MCBTSi weekly ops digest — '.$this->scope,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.executive-digest',
        );
    }
}
