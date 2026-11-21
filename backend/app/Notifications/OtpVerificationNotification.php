<?php

namespace App\Notifications;

use App\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers the six digit verification code by email.
 */
class OtpVerificationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
    ) {}

    /**
     * The delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $intro = match ($this->purpose) {
            OtpPurpose::Registration => 'Completa tu registro en Chat con este código:',
            OtpPurpose::Login => 'Te enviamos un código para iniciar sesión en Chat:',
            OtpPurpose::Sensitive => 'Confirma esta operación con el siguiente código:',
        };

        $greeting = isset($notifiable->nickname) && $notifiable->nickname !== ''
            ? 'Hola '.$notifiable->nickname
            : 'Hola';

        return (new MailMessage)
            ->subject('Tu código de verificación es '.$this->code)
            ->greeting($greeting)
            ->line($intro)
            ->line($this->code)
            ->line('El código expira en 10 minutos. Si no fuiste tú, ignora este mensaje.')
            ->line('Por seguridad, nunca compartas este código con nadie.');
    }
}
