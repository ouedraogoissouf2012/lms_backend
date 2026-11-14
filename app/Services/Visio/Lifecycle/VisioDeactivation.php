<?php

declare(strict_types=1);

namespace App\Services\Visio\Lifecycle;

use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioActorAuthorization;
use App\Support\Logging\CaughtExceptionLog;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Désactive la visio d'une séance, selon la règle de gestion de la visio
 * (`VisioActorAuthorization::canManage`) : l'enseignant propriétaire, ou le
 * personnel de gestion de l'établissement. La même règle qu'activer, démarrer
 * et terminer ; le refus a la même forme (`managementRefusal()`), rendu avant
 * toute écriture.
 *
 * Aucun appel KLASSCI : la séance se lit en local, dans les deux types d'école.
 * Extrait de `VisioActivationService` (§1.1, un service par geste).
 */
final class VisioDeactivation
{
    public function __construct(
        private readonly VisioActorAuthorization $authorization,
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /** @return array{status:int, payload: array<string, mixed>} */
    public function deactivate(int $seanceId, User $user): array
    {
        try {
            $visio = $this->resolveSeance($seanceId);

            if ($visio === null) {
                return self::introuvable();
            }

            if (! $this->authorization->canManage($visio, $user)) {
                return ['status' => 403, 'payload' => VisioActorAuthorization::managementRefusal()];
            }

            if (! $visio->visio_enabled) {
                return self::introuvable();
            }

            $visio->update([
                'visio_enabled' => false,
                'visio_type' => null,
                'visio_status' => null,
                'visio_room_id' => null,
                'visio_active' => false,
                'visio_started_at' => null,
                'updated_by' => $user->id,
            ]);
            $this->logger->info('Visio désactivée', ['seance_id' => $seanceId, 'user_id' => $user->id]);

            return [
                'status' => 200,
                'payload' => ['success' => true, 'message' => 'Visioconférence désactivée', 'data' => ['visio_enabled' => false]],
            ];
        } catch (Throwable $e) {
            $this->journal->error('Erreur désactivation visio', $e, ['seance_id' => $seanceId]);

            return self::erreur();
        }
    }

    /** Résolution dual-ID : ID local d'abord, puis klassci_seance_id (scope de l'établissement en HTTP). */
    private function resolveSeance(int $seanceId): ?Seance
    {
        return Seance::find($seanceId)
            ?? Seance::where('klassci_seance_id', $seanceId)->first();
    }

    /** @return array{status:int, payload: array<string, mixed>} */
    private static function introuvable(): array
    {
        return ['status' => 404, 'payload' => ['success' => false, 'message' => 'Visio non activée pour cette séance']];
    }

    /** @return array{status:int, payload: array<string, mixed>} */
    private static function erreur(): array
    {
        return [
            'status' => 500,
            'payload' => ['success' => false, 'message' => 'Erreur lors de la désactivation', 'error' => 'Une erreur est survenue.'],
        ];
    }
}
