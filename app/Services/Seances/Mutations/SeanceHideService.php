<?php

declare(strict_types=1);

namespace App\Services\Seances\Mutations;

use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\Seances\SeanceDesignee;
use App\Support\Logging\CaughtExceptionLog;
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
 * La séance est désignée par le port reçu de l'appelant (#1566, ADR-1154-01) :
 * dans l'espace de l'école, l'id KLASSCI seul en école KLASSCI, l'id local seul en
 * école autonome. L'ancien `orWhere` sur les deux espaces désignait au hasard
 * sur une collision.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class SeanceHideService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Masque la séance pour l'utilisateur donné.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function hide(int $seanceId, User $user, SeanceDesignee $designee): array
    {
        try {
            $local = \App\Models\Seance::localIdFor($seanceId, $user->institution_id);
            $seance = $local === null ? null : \App\Models\Seance::query()->find($local);

            if (! $seance) {
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
            $this->journal->error('Erreur masquage séance', $e, [
                'seance_id' => $seanceId,
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
    public function unhide(int $seanceId, User $user, SeanceDesignee $designee): array
    {
        try {
            $local = \App\Models\Seance::localIdFor($seanceId, $user->institution_id);
            $seance = $local === null ? null : \App\Models\Seance::query()->find($local);

            if (! $seance) {
                return [
                    'status' => 404,
                    'payload' => [
                        'success' => false,
                        'message' => 'Séance non trouvée',
                    ],
                ];
            }

            $unHidden = SeanceUserHidden::unhide($seance->id, $user->id);

            if (! $unHidden) {
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
            $this->journal->error('Erreur réaffichage séance', $e, [
                'seance_id' => $seanceId,
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
}
