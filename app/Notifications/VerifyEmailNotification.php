<?php

namespace App\Notifications;

use App\Models\EmailVerificationCode;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email confirmation code. Queued and encrypted: the plain code sits in the queue until the email
 * is sent, and must not be readable there.
 */
class VerifyEmailNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $verifyUrl,
        public readonly int $codeId,
    ) {}

    /**
     * Checked by the queue worker right before sending. The job reloads the user, so after an address
     * change or a resend this email would carry a dead code, possibly to the new address: it is dropped,
     * the newer code has its own email.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return EmailVerificationCode::whereKey($this->codeId)
            ->where('email', $notifiable->getEmailForVerification())
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The code is repeated in the subject: it shows in the inbox list, so the address can be
     * confirmed without opening the email.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('verification.email.subject', ['code' => $this->code, 'app' => config('app.name')]))
            ->view('emails.verify-email', [
                'name' => $notifiable->first_name,
                'code' => $this->code,
                'verifyUrl' => $this->verifyUrl,
                'expireMinutes' => EmailVerificationService::CODE_TTL_MINUTES,
            ]);
    }
}
