<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Jobs\EnvoyerLeLienDeReinitialisation;
use App\Services\Mail\CanalDeCourriel;
use App\Services\TenantManager;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * École autonome : le mot de passe local est le seul ; un lien de réinitialisation part par
 * courriel (#808, Brevo), si le canal le permet.
 *
 * - Canal disponible ({@see CanalDeCourriel}) : une tâche est mise en file pour CHAQUE demande,
 *   compte connu ou inconnu ; c'est elle qui cherche le compte et envoie le lien
 *   ({@see EnvoyerLeLienDeReinitialisation}). La requête fait le même travail dans les deux cas.
 * - Canal indisponible (mailer `log`, expéditeur ou URL du front absents) : rien ne part, et
 *   la réponse ne promet rien.
 */
final class LocalMotDePasseOublie implements MotDePasseOublie
{
    public const MOTIF = 'reinitialisation_indisponible';

    public const MOTIF_LIEN_ENVOYE = 'lien_envoye';

    public function __construct(
        private readonly CanalDeCourriel $canal,
        private readonly TenantManager $tenants,
        private readonly Dispatcher $bus,
    ) {}

    public function reponse(string $email): array
    {
        $ecole = $this->tenants->id();

        // FALSIFICATION FA (#808) : la tache part meme canal indisponible.
        if ($ecole !== null) {
            $this->bus->dispatch(new EnvoyerLeLienDeReinitialisation($email, $ecole));
        }

        if ($ecole === null || ! $this->canal->estDisponible()) {
            return $this->corps(self::MOTIF, 'La réinitialisation du mot de passe en ligne n\'est pas encore disponible.');
        }

        $this->bus->dispatch(new EnvoyerLeLienDeReinitialisation($email, $ecole));

        return $this->corps(
            self::MOTIF_LIEN_ENVOYE,
            'Si un compte correspond à cette adresse, un lien pour choisir un nouveau mot de passe va vous être envoyé.',
        );
    }

    /** @return array{status: int, payload: array{success: bool, reason: string, message: string}} */
    private function corps(string $motif, string $message): array
    {
        return ['status' => 200, 'payload' => ['success' => true, 'reason' => $motif, 'message' => $message]];
    }
}
