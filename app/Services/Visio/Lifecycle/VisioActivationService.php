<?php

declare(strict_types=1);

namespace App\Services\Visio\Lifecycle;

use App\Models\Classe;
use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\Notification\AsyncVisioNotificationDispatcher;
use App\Services\Seances\EmploiTempsSeanceLocator;
use App\Services\Seances\KlassciPayload;
use App\Services\Visio\SecureVisioRoomIdGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VisioActivationService — bornes d'état "configuration" du cycle de vie visio
 * (activate / deactivate). Voir {@see VisioSessionService} pour la session live.
 *
 * Extrait verbatim de `LMSVisioLifecycleController::activateVisio` &
 * `deactivateVisio` (split du controller 434l → thin + 2 services SRP).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300l), §1.6 D (DI strict), PSR-3 logger
 */
final class VisioActivationService
{
    public function __construct(
        private readonly EmploiTempsSeanceLocator $seanceLocator,
        private readonly ClasseSyncService $classeSyncService,
        private readonly AsyncVisioNotificationDispatcher $notifications,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Active la visio sur une séance future.
     *
     * @param  array<string, mixed>  $params  Payload validé (réservé futur)
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function activate(int $seanceId, User $user, array $params = []): array
    {
        try {
            $klassciToken = $user->klassci_token;
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
            $this->logger->error('Erreur activation visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
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
     * Désactive la visio sur une séance.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function deactivate(int $seanceId, User $user): array
    {
        try {
            $visio = $this->resolveSeance($seanceId);

            if ($visio === null || ! $visio->visio_enabled) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Visio non activée pour cette séance',
                    ],
                ];
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

            $this->logger->info('Visio désactivée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
            ]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Visioconférence désactivée',
                    'data' => [
                        'visio_enabled' => false,
                    ],
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur désactivation visio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la désactivation',
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

    /** Résolution dual-ID : ID local d'abord, puis klassci_seance_id. */
    private function resolveSeance(int $seanceId): ?Seance
    {
        return Seance::find($seanceId)
            ?? Seance::where('klassci_seance_id', $seanceId)->first();
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
            $this->logger->error('Erreur synchronisation classe', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
            $this->logger->error('Erreur envoi notifications visio programmée', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $notificationsSent;
    }
}
