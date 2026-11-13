<?php

declare(strict_types=1);

namespace App\Services\Visio\Lifecycle;

use App\Models\Seance;
use App\Models\User;

/**
 * La bascule de la visio d'une séance locale (école en mode autonome) : elle
 * DÉLÈGUE, sans rien recopier.
 *
 * - Activer : {@see LocalVisioActivation} — séance désignée par son id local et
 *   bornée à l'établissement, règle de gestion, salon déjà attribué gardé,
 *   élèves avertis.
 * - Désactiver : {@see VisioDeactivation}, commune aux deux types d'école.
 *
 * Seule la forme de la réponse est propre à la bascule (`VisioBasculee`) : elle
 * se relit sur la ligne, après le geste. `visio_type` n'a qu'une valeur, `jitsi`.
 */
final class LocalVisioToggle implements VisioToggleSource
{
    public function __construct(
        private readonly LocalVisioActivation $activation,
        private readonly VisioDeactivation $deactivation,
    ) {}

    public function toggle(int $seanceId, bool $enabled, ?string $visioType, User $user): array
    {
        $resultat = $enabled
            ? $this->activation->activate($seanceId, $user)
            : $this->deactivation->deactivate($seanceId, $user);

        if ($resultat['status'] !== 200) {
            return $resultat;
        }

        $seance = Seance::query()->whereKey($seanceId)->where('institution_id', $user->institution_id)->firstOrFail();

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => $enabled ? 'Visioconférence activée avec succès' : 'Visioconférence désactivée',
                'data' => [
                    'seance_id' => $seance->id,
                    'visio_enabled' => (bool) $seance->visio_enabled,
                    'visio_type' => $seance->visio_type,
                    'visio_room_id' => $seance->visio_room_id,
                    'visio_active' => (bool) $seance->visio_active,
                    // Comme la source KLASSCI : planifiées à l'activation, jamais envoyées pendant la requête.
                    'notifications_sent' => 0,
                    'notifications_queued' => $enabled,
                ],
            ],
        ];
    }
}
