<?php

declare(strict_types=1);

namespace App\Services\Visio\Lifecycle;

use App\Models\Seance;
use App\Models\User;
use App\Services\Notification\AsyncVisioNotificationDispatcher;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use App\Services\Visio\VisioActorAuthorization;
use App\Support\Logging\CaughtExceptionLog;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * L'activation de la visio d'une séance d'école en mode autonome (#926).
 *
 * La séance existe déjà, créée par `LocalSeanceCreator` : rien n'est à chercher
 * ailleurs. Elle est trouvée sous son id LOCAL, bornée explicitement à
 * l'établissement du compte, et activée par qui la gère
 * ({@see VisioActorAuthorization::canManage()} : son auteur, ou le personnel de
 * gestion de l'établissement). Aucun jeton, aucun appel KLASSCI, et rien n'est
 * écrit sous un nom `klassci_*` (D132).
 *
 * La réponse a la forme de la source KLASSCI ({@see VisioActivationService}) : le
 * client ne connaît pas la source. `classe_synced` y vaut toujours faux, puisque
 * rien n'est relu dans KLASSCI.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class LocalVisioActivation implements VisioActivationSource
{
    public function __construct(
        private readonly VisioActorAuthorization $authorization,
        private readonly AsyncVisioNotificationDispatcher $notifications,
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function activate(int $seanceId, User $user, array $params = []): array
    {
        $seance = Seance::query()
            ->whereKey($seanceId)
            ->where('institution_id', $user->institution_id)
            ->first();

        if ($seance === null) {
            return ['status' => 404, 'payload' => ['success' => false, 'message' => 'Séance non trouvée']];
        }

        if (! $this->authorization->canManage($seance, $user)) {
            return ['status' => 403, 'payload' => VisioActorAuthorization::managementRefusal()];
        }

        try {
            $this->activer($seance, $user);
        } catch (Throwable $e) {
            $this->journal->error('Erreur activation visio', $e, ['seance_id' => $seanceId]);

            return [
                'status' => 500,
                'payload' => ['success' => false, 'message' => "Erreur lors de l'activation", 'error' => 'Une erreur est survenue.'],
            ];
        }

        $this->planifierLesNotifications($seance);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => 'Visioconférence activée',
                'data' => [
                    'visio_enabled' => true,
                    'visio_status' => 'programmee',
                    'visio_room_id' => $seance->visio_room_id,
                    'notifications_sent' => 0,
                    'notifications_queued' => true,
                    'classe_synced' => false,
                ],
            ],
        ];
    }

    /**
     * Un salon se frappe une fois (`??=`) : ceux qui ont reçu son adresse la
     * gardent. Même règle que `VisioActivationService::upsertVisio`.
     */
    private function activer(Seance $seance, User $user): void
    {
        $seance->visio_room_id ??= SecureVisioRoomIdGenerator::make();

        $seance->fill([
            'visio_enabled' => true,
            'visio_type' => 'jitsi',
            'visio_status' => 'programmee',
            'visio_active' => false,
            // Rendre la séance visible aux étudiants.
            'is_active' => true,
            'updated_by' => $user->id,
        ])->save();

        $this->logger->info('Visio activée', [
            'seance_id' => $seance->id,
            'user_id' => $user->id,
            'room_id' => $seance->visio_room_id,
        ]);
    }

    /** Notifie les étudiants que la visio est programmée. Échec silencieux, comme la source KLASSCI. */
    private function planifierLesNotifications(Seance $seance): void
    {
        try {
            $this->notifications->queueScheduled($seance->id, [
                // La séance, désignée par son id local : ses élèves sont ceux de l'admission.
                'seance_locale_id' => $seance->id,
                'klassci_classe_id' => $seance->klassci_classe_id,
                'klassci_enseignant_id' => $seance->klassci_enseignant_id,
                'matiere_nom' => $seance->matiere_nom,
                'enseignant_nom' => $seance->enseignant_nom,
            ]);
        } catch (Throwable $e) {
            $this->journal->error('Erreur envoi notifications visio programmée', $e, [
                'seance_id' => $seance->id,
            ]);
        }
    }
}
