<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Password reset email. Queued, so the forgot-password form answers equally fast
 * whether or not the email belongs to an account.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable): MailMessage
    {
        $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject(__('passwords.email.subject', ['app' => config('app.name')]))
            ->view('emails.reset-password', [
                'name' => $notifiable->first_name,
                'resetUrl' => $this->resetUrl($notifiable),
                'expireMinutes' => $expire,
            ]);
    }

    protected function resetUrl($notifiable): string
    {
        return route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
