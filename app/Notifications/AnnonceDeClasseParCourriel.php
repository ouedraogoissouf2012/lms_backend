<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le courriel d'une annonce à une classe (#1650). Il reprend le texte de l'annonce, tel quel, signé de l'école. La
 * notification dans le LMS est écrite à part, avant, pour chaque élève : ce courriel n'en est que l'écho.
 */
final class AnnonceDeClasseParCourriel extends Notification
{
    /** @param  string  $classe  « la classe 3e A », ou « votre classe » si elle n'existe plus */
    public function __construct(
        private readonly string $titre,
        private readonly string $message,
        private readonly string $classe,
        private readonly string $ecole,
    ) {
        $this->locale = 'fr';
    }

    /** @return list<string> */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->ecole} : {$this->titre}")
            ->greeting('Bonjour,')
            ->line("Annonce à {$this->classe} :")
            ->line($this->message)
            ->line('Vous la retrouvez aussi dans vos notifications.')
            ->salutation($this->ecole);
    }
}
