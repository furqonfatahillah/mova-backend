<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $userName;
    public string $otpCode;
    public int $expiryMinutes;

    /**
     * Create a new message instance.
     */
    public function __construct(string $userName, string $otpCode, int $expiryMinutes = 15)
    {
        $this->userName = $userName;
        $this->otpCode = $otpCode;
        $this->expiryMinutes = $expiryMinutes;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[MOVA POS] {$this->otpCode} adalah Kode Verifikasi Reset Password Anda",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.reset_password_otp',
            with: [
                'userName'      => $this->userName,
                'otpCode'       => $this->otpCode,
                'expiryMinutes' => $this->expiryMinutes,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
