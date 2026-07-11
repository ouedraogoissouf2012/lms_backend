<?php

declare(strict_types=1);

namespace App\Services\Visio\Lifecycle;

use App\Models\Classe;
use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\NotificationService;
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
        private readonly KlassciProxyService $klassciService,
        private readonly ClasseSyncService $classeSyncService,
        private readonly NotificationService $notificationService,
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
            [$seanceFound, $matiereInfo] = $this->locateKlassciSeance($seanceId, $user, $klassciToken);

            if (! $seanceFound) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Séance non trouvée',
                    ],
                ];
            }

            // Créer ou mettre à jour l'entrée visio
            $visio = Seance::updateOrCreate(
                ['klassci_seance_id' => $seanceId],
                [
                    'klassci_matiere_id' => $matiereInfo['id'] ?? null,
                    'klassci_classe_id' => $seanceFound['classe']['id'] ?? null,
                    'klassci_enseignant_id' => $user->klassci_id,
                    'enseignant_nom' => $user->name,
                    'matiere_nom' => $matiereInfo['nom'] ?? $matiereInfo['libelle'] ?? null,
                    'visio_enabled' => true,
                    'visio_type' => 'jitsi',
                    'visio_status' => 'programmee',
                    'visio_room_id' => SecureVisioRoomIdGenerator::make(),
                    'visio_active' => false,
                    'is_active' => true,  // S'assurer que la séance est active pour être visible aux étudiants
                    'updated_by' => $user->id,
                ]
            );

            $this->logger->info('Visio activée', [
                'seance_id' => $seanceId,
                'user_id' => $user->id,
                'room_id' => $visio->visio_room_id,
            ]);

            // Synchroniser la classe et ses étudiants depuis Klassci
            // pour que les notifications puissent être envoyées
            $classe = $this->syncClasseForNotifications($visio, $klassciToken, $seanceId);

            // Envoyer les notifications aux étudiants
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
     * Cherche la séance dans Klassci via le bon endpoint selon le rôle.
     * Conserve le comportement legacy verbatim.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null} [seanceFound, matiereInfo]
     */
    private function locateKlassciSeance(int $seanceId, User $user, ?string $klassciToken): array
    {
        // Pour enseignants: teacher-dashboard ; pour coordinateurs: /matieres
        if ($user->isTeacher()) {
            $dashboard = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/teacher-dashboard',
                'GET'
            );
            $matieres = collect($dashboard['data']['matieres'] ?? []);
        } else {
            $matieresResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'matieres',
                'GET'
            );
            $matieres = collect($matieresResponse['data'] ?? []);
        }

        foreach ($matieres as $matiere) {
            $matiereDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "matieres/{$matiere['id']}",
                'GET'
            );

            $seances = collect($matiereDetails['data']['seances_programmees'] ?? []);
            $seanceFound = $seances->firstWhere('id', $seanceId);

            if ($seanceFound) {
                return [$seanceFound, $matiere];
            }
        }

        return [null, null];
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
            $notificationsSent = $this->notificationService->notifyVisioScheduled($seanceId, [
                'klassci_classe_id' => $visio->klassci_classe_id,
                'klassci_enseignant_id' => $visio->klassci_enseignant_id,
                'matiere_nom' => $visio->matiere_nom,
                'enseignant_nom' => $visio->enseignant_nom,
            ]);
            $this->logger->info('Notifications visio programmée envoyées', [
                'seance_id' => $seanceId,
                'notifications_sent' => $notificationsSent,
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
