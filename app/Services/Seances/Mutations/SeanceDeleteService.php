<?php

declare(strict_types=1);

namespace App\Services\Seances\Mutations;

use App\Models\Seance;
use App\Models\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SeanceDeleteService — extrait verbatim de
 * `LMSSeancesMutationController::deleteSeance()`.
 *
 * Effectue le soft-delete d'une séance après vérification qu'aucune
 * visioconférence n'est en cours sur celle-ci.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class SeanceDeleteService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Supprime (soft delete) la séance demandée.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function delete(int $seanceId, User $user): array
    {
        try {
            $seance = Seance::find($seanceId);
            if (!$seance) {
                $seance = Seance::where('klassci_seance_id', $seanceId)->first();
            }

            if (!$seance) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Séance non trouvée',
                    ],
                ];
            }

            if ($seance->visio_active) {
                return [
                    'status' => 422,
                    'payload' => [
                        'success' => false,
                        'message' => 'Impossible de supprimer une séance dont la visioconférence est en cours.',
                    ],
                ];
            }

            $this->logger->info('Suppression séance', [
                'seance_id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'deleted_by' => $user->id,
                'user_role' => $user->role,
            ]);

            $seance->delete();

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Séance supprimée avec succès',
                ],
            ];

        } catch (Throwable $e) {
            $this->logger->error('Erreur suppression séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors de la suppression de la séance',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }
}
