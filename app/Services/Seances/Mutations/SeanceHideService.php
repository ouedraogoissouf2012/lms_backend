<?php

declare(strict_types=1);

namespace App\Services\Seances\Mutations;

use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SeanceHideService — extrait verbatim de
 * `LMSSeancesMutationController::hideSeance()` et `unhideSeance()`.
 *
 * Gère le masquage individuel d'une séance par un étudiant (table
 * `seance_user_hidden`). Le masquage est strictement personnel : il
 * n'affecte pas l'affichage pour les autres utilisateurs.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class SeanceHideService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Masque la séance pour l'utilisateur donné.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function hide(int $seanceId, User $user): array
    {
        try {
            $seance = $this->resolveSeance($seanceId);

            if (!$seance) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Séance non trouvée',
                    ],
                ];
            }

            SeanceUserHidden::hide($seance->id, $user->id);

            $this->logger->info('Séance masquée par étudiant', [
                'seance_id' => $seance->id,
                'user_id' => $user->id,
                'matiere' => $seance->matiere_nom,
            ]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Séance masquée avec succès',
                    'data' => [
                        'seance_id' => $seance->id,
                        'hidden_at' => now()->format('Y-m-d H:i:s'),
                    ],
                ],
            ];

        } catch (Throwable $e) {
            $this->logger->error('Erreur masquage séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors du masquage de la séance',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Réaffiche une séance précédemment masquée par l'utilisateur.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function unhide(int $seanceId, User $user): array
    {
        try {
            $seance = $this->resolveSeance($seanceId);

            if (!$seance) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Séance non trouvée',
                    ],
                ];
            }

            $unHidden = SeanceUserHidden::unhide($seance->id, $user->id);

            if (!$unHidden) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'La séance n\'était pas masquée',
                    ],
                ];
            }

            $this->logger->info('Séance réaffichée par étudiant', [
                'seance_id' => $seance->id,
                'user_id' => $user->id,
            ]);

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Séance réaffichée avec succès',
                    'data' => [
                        'seance_id' => $seance->id,
                    ],
                ],
            ];

        } catch (Throwable $e) {
            $this->logger->error('Erreur réaffichage séance', [
                'seance_id' => $seanceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Erreur lors du réaffichage de la séance',
                    'error' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Recherche la séance par son ID local puis, à défaut, par `klassci_seance_id`.
     */
    private function resolveSeance(int $seanceId): ?Seance
    {
        return Seance::where('id', $seanceId)
            ->orWhere('klassci_seance_id', $seanceId)
            ->first();
    }
}
