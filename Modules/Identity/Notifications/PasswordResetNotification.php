<?php

namespace Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $query = http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $resetUrl = rtrim((string) config('identity.frontend_url'), '/').'/reset-password?'.$query;

        return (new MailMessage)
            ->subject('Reset your CollabSpace password')
            ->line('We received a request to reset the password for your CollabSpace account.')
            ->action('Reset Password', $resetUrl)
            ->line('This link expires in '.(int) config('auth.passwords.users.expire', 60).' minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
