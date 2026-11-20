<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * #808 — le courriel qui porte le lien de réinitialisation (Brevo, par le mailer SMTP).
 *
 * Il ne dit rien du compte au-delà de ce que le titulaire sait déjà : le lien, sa durée, son
 * usage unique, et que faire si la demande ne vient pas de lui. Envoyé depuis la tâche
 * `EnvoyerLeLienDeReinitialisation`, qui tourne déjà en file.
 *
 * En français quelle que soit `APP_LOCALE` : le gabarit de Laravel imprime aussi ses propres
 * phrases (aide sous le bouton, pied de page), traduites dans `lang/fr.json`.
 */
final class LienDeReinitialisationDuMotDePasse extends Notification
{
    public function __construct(
        private readonly string $lien,
        private readonly int $minutes,
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
            ->subject('Choisir un nouveau mot de passe')
            ->greeting('Bonjour,')
            ->line('Une demande de réinitialisation du mot de passe a été faite pour votre compte.')
            ->action('Choisir un nouveau mot de passe', $this->lien)
            ->line("Ce lien est valable {$this->minutes} minutes et ne sert qu'une fois.")
            ->line("Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe ne change pas.")
            ->salutation('À bientôt.');
    }
}
