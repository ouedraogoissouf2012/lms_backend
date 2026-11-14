<?php

declare(strict_types=1);

namespace App\Services\Visio\Lifecycle;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Classe;
use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\Notification\AsyncVisioNotificationDispatcher;
use App\Services\Seances\EmploiTempsSeanceLocator;
use App\Services\Seances\KlassciPayload;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use App\Support\Logging\CaughtExceptionLog;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VisioActivationService — l'activation de la visio d'une séance KLASSCI. Voir
 * {@see VisioSessionService} pour la session live, {@see VisioDeactivation} pour
 * la désactivation, commune aux deux types d'école.
 *
 * Extrait de `LMSVisioLifecycleController::activateVisio` (split du controller
 * 434l → thin + services SRP).
 *
 * Source KLASSCI de {@see VisioActivationSource} : une école en mode autonome
 * active par {@see LocalVisioActivation}.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300l), §1.6 D (DI strict), PSR-3 logger
 */
final class VisioActivationService implements VisioActivationSource
{
    public function __construct(
        private readonly EmploiTempsSeanceLocator $seanceLocator,
        private readonly ClasseSyncService $classeSyncService,
        private readonly AsyncVisioNotificationDispatcher $notifications,
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Active la visio sur une séance future.
     *
     * Sans jeton KLASSCI : 401 motivé (#806). La séance n'est cherchée que dans
     * l'emploi du temps KLASSCI ({@see EmploiTempsSeanceLocator::locate()}) ;
     * sans jeton, cette recherche rendait « Séance non trouvée », une absence
     * présentée comme un 404. Le refus vivait auparavant dans la FormRequest,
     * en 403 générique.
     *
     * @param  array<string, mixed>  $params  Payload validé (réservé futur)
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function activate(int $seanceId, User $user, array $params = []): array
    {
        $klassciToken = $user->klassci_token;
        if (! is_string($klassciToken) || $klassciToken === '') {
            return ['status' => 401, 'payload' => MissingKlassciTokenException::payload()];
        }

        try {
            [$seanceFound, $matiereInfo] = $this->seanceLocator->locate($seanceId, $user, $klassciToken);

            if (! $seanceFound) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Séance non trouvée',
                    ],
                ];
            }

            $visio = $this->upsertVisio($seanceId, $seanceFound, $matiereInfo, $user);

            $this->logger->info('Visio activée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'room_id' => $visio->visio_room_id,
            ]);

            // Synchroniser la classe et ses étudiants depuis Klassci
            // pour que les notifications puissent être envoyées
            $classe = $this->syncClasseForNotifications($visio, $klassciToken, $seanceId);

            // Planifier les notifications aux étudiants.
            $notificationsSent = $this->sendScheduledNotifications($visio, $seanceId, $classe);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Visioconférence activée',
                    'data' => [
                        'visio_enabled' => true,
                        'visio_status' => 'programmee',
                        'visio_room_id' => $visio->visio_room_id,
                        'notifications_sent' => $notificationsSent,
                        'notifications_queued' => true,
                        'classe_synced' => $classe !== null,
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->journal->error('Erreur activation visio', $e, [
                'seance_id' => $seanceId,
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => "Erreur lors de l'activation",
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Pose l'entrée visio de la séance, SANS jamais réattribuer un salon.
     *
     * ## Un salon se frappe une fois
     *
     * La version précédente passait `visio_room_id => make()` en valeur
     * inconditionnelle d'un `updateOrCreate`. Tant que la résolution KLASSCI
     * échouait (#739), ce chemin était inatteignable et le défaut dormait.
     *
     * Il ne dort plus. `SeanceUpsertService::create()` — la SYNCHRONISATION —
     * crée déjà chaque séance avec un salon ET notifie les étudiants. Activer
     * une séance déjà synchronisée aurait donc changé l'adresse sous les pieds
     * de ceux qui l'avaient reçue : liens morts, et cours en train de se tenir
     * coupé net.
     *
     * D'où `??=` : la valeur n'est frappée que si la place est libre.
     *
     * @param  array<string, mixed>  $seanceFound
     * @param  array<string, mixed>|null  $matiereInfo
     */
    private function upsertVisio(int $seanceId, array $seanceFound, ?array $matiereInfo, User $user): Seance
    {
        $visio = Seance::firstOrNew(['klassci_seance_id' => $seanceId]);

        $visio->visio_room_id ??= SecureVisioRoomIdGenerator::make();

        $visio->fill([
            'klassci_matiere_id' => $matiereInfo['id'] ?? null,
            'klassci_classe_id' => KlassciPayload::toInt(
                KlassciPayload::asArray($seanceFound['classe'] ?? null)['id'] ?? null
            ),
            'klassci_enseignant_id' => $user->klassci_id,
            'enseignant_nom' => $user->name,
            'matiere_nom' => $matiereInfo['nom'] ?? $matiereInfo['libelle'] ?? null,
            'visio_enabled' => true,
            'visio_type' => 'jitsi',
            'visio_status' => 'programmee',
            'visio_active' => false,
            // Rendre la séance visible aux étudiants.
            'is_active' => true,
            'updated_by' => $user->id,
        ])->save();

        return $visio;
    }

    /** Sync classe Klassci pour rendre les notifications possibles. Échec silencieux. */
    private function syncClasseForNotifications(Seance $visio, ?string $klassciToken, int $seanceId): ?Classe
    {
        $classe = null;
        try {
            if ($visio->klassci_classe_id && $klassciToken !== null) {
                $this->logger->info('Synchronisation classe pour notifications', [
                    'klassci_classe_id' => $visio->klassci_classe_id,
                ]);
                $classe = $this->classeSyncService->syncClasseById(
                    $visio->klassci_classe_id,
                    $klassciToken
                );
                if ($classe !== null) {
                    $this->logger->info('Classe synchronisée avec étudiants', [
                        'classe_id' => $classe->id,
                        'klassci_id' => $classe->klassci_id,
                        'libelle' => $classe->libelle,
                        'etudiants_actifs' => $classe->etudiantsActifs()->count(),
                    ]);
                } else {
                    $this->logger->warning('Synchronisation classe échouée - classe null', [
                        'klassci_classe_id' => $visio->klassci_classe_id,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $this->journal->error('Erreur synchronisation classe', $e, [
                'seance_id' => $seanceId,
            ]);
        }

        return $classe;
    }

    /** Notifie les étudiants que la visio est programmée. Échec silencieux. */
    private function sendScheduledNotifications(Seance $visio, int $seanceId, ?Classe $classe): int
    {
        $notificationsSent = 0;
        try {
            $this->notifications->queueScheduled($seanceId, [
                // L'id LOCAL de la séance : la clé de la garde d'idempotence (V1).
                'seance_locale_id' => $visio->id,
                'klassci_classe_id' => $visio->klassci_classe_id,
                'klassci_enseignant_id' => $visio->klassci_enseignant_id,
                'matiere_nom' => $visio->matiere_nom,
                'enseignant_nom' => $visio->enseignant_nom,
            ]);
            $this->logger->info('Notifications visio programmée planifiées', [
                'seance_id' => $seanceId,
                'classe_local_id' => $classe?->id,
            ]);
        } catch (Throwable $e) {
            $this->journal->error('Erreur envoi notifications visio programmée', $e, [
                'seance_id' => $seanceId,
            ]);
        }

        return $notificationsSent;
    }
}
