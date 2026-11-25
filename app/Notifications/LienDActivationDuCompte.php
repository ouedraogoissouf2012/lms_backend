<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * #1620 — le courriel qui porte le lien d'activation du titulaire d'une école qu'on vient d'ouvrir
 * (Brevo, par le mailer SMTP).
 *
 * Le lien est celui que le supradmin a reçu en secours : il n'y en a qu'un. Le courriel dit
 * l'école, la durée du lien et son usage unique, rien d'autre. Envoyé depuis la tâche
 * `EnvoyerLeLienDActivation`, qui tourne déjà en file.
 *
 * En français quelle que soit `APP_LOCALE` (patron de `LienDeReinitialisationDuMotDePasse`).
 */
final class LienDActivationDuCompte extends Notification
{
    public function __construct(
        private readonly string $lien,
        private readonly string $ecole,
        private readonly int $jours,
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
            ->subject("Votre école {$this->ecole} est ouverte")
            ->greeting('Bonjour,')
            ->line("Votre demande d'ouverture a été acceptée : l'école {$this->ecole} est ouverte, et vous en êtes l'administrateur.")
            ->line('Pour accéder à votre espace, choisissez votre mot de passe.')
            ->action('Activer mon compte', $this->lien)
            ->line("Ce lien est valable {$this->jours} jours et ne sert qu'une fois.")
            ->salutation('À bientôt.');
    }
}
