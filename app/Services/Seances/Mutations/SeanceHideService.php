<?php

declare(strict_types=1);

namespace App\Services\Seances\Mutations;

use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * Masque la séance pour l'utilisateur donné.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function hide(int $seanceId, User $user): array
    {
        try {
            $seance = $this->resolveSeance($seanceId, $user);

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
    public function unhide(int $seanceId, User $user): array
    {
        try {
            $seance = $this->resolveSeance($seanceId, $user);

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

    /**
     * La séance désignée par l'id local ou par `klassci_seance_id`, dans
     * l'établissement du compte : sans établissement, aucune (le scope global est
     * inerte sans tenant). L'ORDRE entre les deux identifiants, sur une collision,
     * est inchangé : il attend sa décision.
     */
    private function resolveSeance(int $seanceId, User $user): ?Seance
    {
        if (!is_int($user->institution_id)) {
            return null;
        }

        return Seance::query()
            ->where('institution_id', $user->institution_id)
            ->where(static fn (Builder $designee): Builder => $designee->where('id', $seanceId)->orWhere('klassci_seance_id', $seanceId))
            ->first();
    }
}
