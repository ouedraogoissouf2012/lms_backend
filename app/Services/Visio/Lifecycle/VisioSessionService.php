<?php

declare(strict_types=1);

namespace App\Services\Visio\Lifecycle;

use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\Notification\AsyncVisioNotificationDispatcher;
use App\Services\Visio\VisioActorAuthorization;
use App\Support\Logging\CaughtExceptionLog;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VisioSessionService — extrait verbatim de
 * `LMSVisioLifecycleController::startVisio()` et `endVisio()`.
 *
 * Gère les bornes d'état "session live" du cycle de vie d'une visio
 * (une fois qu'elle a été configurée puis activée) :
 *  - start : l'enseignant démarre la session, status=active, visio_active=true,
 *            re-synchronise la classe par sécurité (idempotent) puis notifie.
 *  - end   : l'enseignant termine la session, status=terminee, visio_active=false,
 *            visio_ended_at=now.
 *
 * Ces deux opérations sont les bornes d'état de la même machine (durée
 * d'une session live) — elles vivent ensemble dans un service cohésif,
 * séparé de la configuration initiale (cf. {@see VisioActivationService}).
 *
 * @see PRODUCTION_STANDARDS.md §1.1   — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (constructor injection)
 * @see PRODUCTION_STANDARDS.md PSR-3  — LoggerInterface au lieu de Facade Log
 */
final class VisioSessionService
{
    public function __construct(
        private readonly ClasseSyncService $classeSyncService,
        private readonly AsyncVisioNotificationDispatcher $notifications,
        private readonly VisioActorAuthorization $authorization,
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Démarre la visio (passe de programmee à active).
     *
     * @param  array<string, mixed>  $params  Payload validé (réservé futur)
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function start(int $seanceId, User $user, array $params = []): array
    {
        try {
            $visio = $this->seanceGeree($seanceId, $user, 'Visio non activée pour cette séance');

            if (is_array($visio)) {
                return $visio;
            }

            if (! $visio->visio_enabled) {
                return [
                    'status' => 400,
                    'payload' => [
                        'success' => false,
                        'message' => 'La visio doit être activée avant de démarrer',
                    ],
                ];
            }

            $visio->update([
                'visio_status' => 'active',
                'visio_active' => true,
                'visio_started_at' => now(),
                'updated_by' => $user->id,
            ]);

            $this->logger->info('Visio démarrée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'started_at' => $visio->visio_started_at,
            ]);

            // Synchroniser la classe si pas déjà fait (sécurité)
            // Normalement déjà fait lors de activate(), mais on s'assure
            $this->ensureClasseSynced($visio, $user, $seanceId);

            // Planifier les notifications aux étudiants et à l'enseignant.
            $this->sendStartingNotifications($visio, $seanceId);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Visioconférence démarrée',
                    'data' => [
                        'visio_status' => 'active',
                        'visio_started_at' => $visio->visio_started_at,
                        'visio_room_id' => $visio->visio_room_id,
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->journal->error('Erreur démarrage visio', $e, [
                'seance_id' => $seanceId,
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors du démarrage',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Termine la visio manuellement (passe de active à terminee).
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function end(int $seanceId, User $user): array
    {
        try {
            $visio = $this->seanceGeree($seanceId, $user, 'Visio non trouvée');

            if (is_array($visio)) {
                return $visio;
            }

            if ($visio->visio_status !== 'active') {
                return [
                    'status' => 400,
                    'payload' => [
                        'success' => false,
                        'message' => "La visio n'est pas active",
                    ],
                ];
            }

            $visio->update([
                'visio_status' => 'terminee',
                'visio_active' => false,
                'visio_ended_at' => now(),
                'updated_by' => $user->id,
            ]);

            $this->logger->info('Visio terminée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'ended_at' => $visio->visio_ended_at,
                'participants_count' => $visio->current_participants_count,
            ]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Visioconférence terminée',
                    'data' => [
                        'visio_status' => 'terminee',
                        'visio_ended_at' => $visio->visio_ended_at,
                        'participants_count' => $visio->current_participants_count,
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->journal->error('Erreur fin visio', $e, [
                'seance_id' => $seanceId,
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la terminaison',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * La séance, si l'acteur la gère ; sinon le refus à relayer.
     *
     * La règle est celle de la gestion de la visio
     * ({@see VisioActorAuthorization::canManage()}) : l'enseignant propriétaire,
     * ou le personnel de gestion de l'établissement. Elle porte sur la séance
     * déjà résolue, avant tout contrôle d'état, toute écriture et toute
     * notification.
     *
     * @return Seance|array{status:int, payload: array<string, mixed>}
     */
    private function seanceGeree(int $seanceId, User $user, string $absente): Seance|array
    {
        $visio = $this->resolveSeance($seanceId);

        if ($visio === null) {
            return ['status' => 404, 'payload' => ['success' => false, 'message' => $absente]];
        }

        if (! $this->authorization->canManage($visio, $user)) {
            return [
                'status' => 403,
                'payload' => [
                    'success' => false,
                    'message' => 'Vous ne pouvez pas gérer cette visio',
                    'reason' => 'actor_not_authorized',
                ],
            ];
        }

        return $visio;
    }

    /**
     * Résolution dual-ID : ID local d'abord, puis klassci_seance_id.
     * Conserve le comportement legacy verbatim.
     */
    private function resolveSeance(int $seanceId): ?Seance
    {
        $visio = Seance::withConnectedParticipantsCount()->find($seanceId);

        if ($visio === null) {
            $visio = Seance::where('klassci_seance_id', $seanceId)->withConnectedParticipantsCount()->first();
        }

        return $visio;
    }

    /**
     * Re-sync sécurité de la classe au démarrage. Échec silencieux.
     */
    private function ensureClasseSynced(Seance $visio, User $user, int $seanceId): void
    {
        try {
            if ($visio->klassci_classe_id) {
                $klassciToken = $user->klassci_token;
                if ($klassciToken) {
                    $this->classeSyncService->syncClasseById(
                        $visio->klassci_classe_id,
                        $klassciToken
                    );
                }
            }
        } catch (Throwable $e) {
            $this->journal->error('Erreur synchronisation classe au démarrage', $e, [
                'seance_id' => $seanceId,
            ]);
        }
    }

    /**
     * Notifie les étudiants et l'enseignant du démarrage de la visio.
     * Échec silencieux.
     */
    private function sendStartingNotifications(Seance $visio, int $seanceId): void
    {
        try {
            $this->notifications->queueStarting($seanceId, [
                // La séance résolue, par son id local : sans classe KLASSCI, ses
                // élèves sont ceux de l'admission. Une séance miroitée ne change pas.
                'seance_locale_id' => $visio->id,
                'klassci_classe_id' => $visio->klassci_classe_id,
                'klassci_enseignant_id' => $visio->klassci_enseignant_id,
                'matiere_nom' => $visio->matiere_nom,
                'enseignant_nom' => $visio->enseignant_nom,
            ]);

            $this->logger->info('Notifications visio démarrée planifiées', [
                'seance_id' => $seanceId,
            ]);
        } catch (Throwable $e) {
            $this->journal->error('Erreur envoi notifications visio démarrée', $e, [
                'seance_id' => $seanceId,
            ]);
        }
    }
}
