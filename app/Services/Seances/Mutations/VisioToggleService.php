<?php

declare(strict_types=1);

namespace App\Services\Seances\Mutations;

use App\Http\Controllers\API\LMS\Concerns\FetchesSeanceDataFromKlassci;
use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use App\Services\NotificationService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * VisioToggleService — extrait verbatim de
 * `LMSSeancesMutationController::toggleVisioSeance()`.
 *
 * Active ou désactive la visioconférence pour une séance, met à jour
 * l'entrée locale `seances` et — quand l'activation a lieu — envoie les
 * notifications aux étudiants concernés via `NotificationService`.
 *
 * Workaround historique préservé : pas d'endpoint KLASSCI `GET /seances/{id}`,
 * donc les IDs de matière/classe/enseignant sont initialement null puis
 * éventuellement complétés par `getSeanceDataFromKlassci()`.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class VisioToggleService
{
    use FetchesSeanceDataFromKlassci;

    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly ClasseSyncService $classeSyncService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Toggle la visio pour la séance donnée.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function toggle(
        int $seanceId,
        bool $enabled,
        ?string $visioType,
        User $user,
    ): array {
        try {
            $visioType = $visioType ?? 'jitsi';
            $klassciToken = $user->klassci_token;

            $this->logger->info('Toggle visio séance', [
                'klassci_seance_id' => $seanceId,
                'enabled' => $enabled,
                'visio_type' => $visioType,
                'user_id' => $user->id,
            ]);

            // WORKAROUND: pas d'endpoint GET /seances/{id} dans KLASSCI.
            // On crée/met à jour directement l'entrée locale ; les IDs
            // matière/classe/enseignant restent null tant que `getSeanceDataFromKlassci`
            // ne les complète pas, mais cela ne casse rien : les données réelles
            // viennent toujours de KLASSCI via /matieres/{id}.
            $this->logger->info('Création/MAJ entrée visio locale sans vérification KLASSCI', [
                'klassci_seance_id' => $seanceId,
                'note' => 'Workaround: endpoint GET /seances/{id} inexistant dans KLASSCI',
            ]);

            $visio = Seance::updateOrCreate(
                ['klassci_seance_id' => $seanceId],
                [
                    'klassci_matiere_id' => null,
                    'klassci_classe_id' => null,
                    'klassci_enseignant_id' => null,
                    'visio_enabled' => $enabled,
                    'visio_type' => $enabled ? $visioType : null,
                    'visio_status' => $enabled ? 'programmee' : null,
                    'visio_room_id' => $enabled ? 'lms_seance_' . $seanceId . '_' . time() : null,
                    'visio_active' => false,
                    'updated_by' => $user->id,
                ]
            );

            if ($visio->wasRecentlyCreated) {
                $visio->created_by = $user->id;
                $visio->save();
            }

            $notificationsSent = 0;
            if ($enabled && $klassciToken !== null && $klassciToken !== '') {
                $notificationsSent = $this->dispatchNotifications($seanceId, $visio, (string) $klassciToken);
            }

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => $enabled ? 'Visioconférence activée avec succès' : 'Visioconférence désactivée',
                    'data' => [
                        'seance_id' => $seanceId,
                        'visio_enabled' => $visio->visio_enabled,
                        'visio_type' => $visio->visio_type,
                        'visio_room_id' => $visio->visio_room_id,
                        'visio_active' => $visio->visio_active,
                        'notifications_sent' => $notificationsSent,
                    ],
                ],
            ];

        } catch (Throwable $e) {
            $this->logger->error('Erreur toggle visio séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de l\'activation/désactivation de la visio',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Synchronise la classe puis envoie les notifications aux étudiants.
     */
    private function dispatchNotifications(int $seanceId, Seance $visio, string $klassciToken): int
    {
        try {
            $seanceData = $this->getSeanceDataFromKlassci($seanceId, $klassciToken);

            if (!$seanceData) {
                return 0;
            }

            $visio->update([
                'klassci_matiere_id' => $seanceData['matiere_id'] ?? null,
                'klassci_classe_id' => $seanceData['classe_id'] ?? null,
                'klassci_enseignant_id' => $seanceData['enseignant_id'] ?? null,
                'matiere_nom' => $seanceData['matiere_nom'] ?? null,
                'enseignant_nom' => $seanceData['enseignant_nom'] ?? null,
            ]);

            if (isset($seanceData['classe_id'])) {
                $this->classeSyncService->syncClasseById(
                    (int) $seanceData['classe_id'],
                    $klassciToken,
                );
            }

            $notificationsSent = $this->notificationService->notifyVisioScheduled($seanceId, [
                'klassci_classe_id' => $seanceData['classe_id'] ?? null,
                'klassci_enseignant_id' => $seanceData['enseignant_id'] ?? null,
                'matiere_nom' => $seanceData['matiere_nom'] ?? 'Matière',
                'enseignant_nom' => $seanceData['enseignant_nom'] ?? 'Enseignant',
            ]);

            $this->logger->info('Notifications visio envoyées via toggleVisio', [
                'seance_id' => $seanceId,
                'notifications_sent' => $notificationsSent,
            ]);

            return $notificationsSent;
        } catch (Throwable $e) {
            $this->logger->error('Erreur envoi notifications via toggleVisio', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
