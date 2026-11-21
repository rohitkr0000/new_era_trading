<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One branded mailable for every e-mail (resources/views/emails/<template>.blade.php).
 * The sender is always config('mail.from') = "New Era Trading <no-reply@neweratrading.net>".
 */
class BrandedMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $template, public array $data = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.' . $this->template, with: $this->data + ['support' => config('app.support_email')]);
    }
}
