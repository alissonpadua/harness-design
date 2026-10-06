<?php

declare(strict_types=1);

namespace App\Notifications\Mailables;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one email shape for every catalog type (Q6): shared markdown layout,
 * types provide subject + title + body lines + optional action only.
 */
final class CatalogMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, string>  $lines
     */
    public function __construct(
        public readonly string $subjectText,
        public readonly string $heading,
        public readonly array $lines,
        public readonly string $accent,
        public readonly string $appName,
        public readonly ?string $actionText = null,
        public readonly ?string $actionUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectText);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.catalog',
            with: [
                'heading' => $this->heading,
                'lines' => $this->lines,
                'accent' => $this->accent,
                'appName' => $this->appName,
                'actionText' => $this->actionText,
                'actionUrl' => $this->actionUrl,
            ],
        );
    }
}
