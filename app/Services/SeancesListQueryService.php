<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeDeSeance;
use App\Exceptions\MissingKlassciTokenException;
use App\Http\Controllers\API\LMS\LMSSeancesListController;
use App\Models\User;
use App\Services\Seances\CartesDuType;
use App\Services\Seances\FenetreVisio;
use App\Services\Seances\KlassciModificationDeSeance;
use App\Services\Seances\SeancesListReader;
use App\Services\Seances\SeancesWindow;
use App\Services\Seances\StudentClassesSeancesFetcher;
use App\Services\Seances\TeachingSeancesFetcher;
use App\Services\Seances\UpcomingSeancesFetcher;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * SeancesListQueryService — listings of séances scoped to the current user.
 *
 * Extracted from the legacy `LMSSeancesQueryController` (922 lines, §5 violation)
 * during split-1.
 *
 * ## Responsibility (SRP)
 *
 * Endpoints that return a **list** of séances for the current user:
 *   - `getUpcomingForUser()` — upcoming séances within a date window.
 *   - `getMyTeachingForUser()` — séances I (teacher) am teaching.
 *   - `getMyClassesForUser()` — séances of the class I (student) belong to.
 *
 * Single-séance queries live in `SeanceDetailQueryService`. Historical queries
 * live in `SeancesHistoryQueryService`.
 *
 * ## Architecture
 *
 * This service is intentionally thin (≤300 lines) and delegates to three
 * dedicated fetcher helpers (one per use-case):
 *   - `UpcomingSeancesFetcher`
 *   - `TeachingSeancesFetcher`
 *   - `StudentClassesSeancesFetcher`
 *
 * ## Contract
 *
 * - Successful resolution → returns an associative array with `data` and `meta`.
 * - Missing KLASSCI token on user → throws `MissingKlassciTokenException` (401).
 * - KLASSCI answers an error → `RuntimeException` porting ITS status, relayed as-is.
 * - Student without resolvable class (`getMyClassesForUser`) → returns
 *   `['classe_missing' => true]` (caller renders 404).
 * - Student without matières → returns `['empty_matieres' => true]`
 *   (caller renders 200 with empty data + informational message).
 * - Unexpected errors propagate up (caller logs and renders 500).
 *
 * Source KLASSCI du port {@see SeancesListReader} (ADR-EI-01, lot 3d).
 *
 * @see LMSSeancesListController (route handler)
 */
final class SeancesListQueryService implements SeancesListReader
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UpcomingSeancesFetcher $upcomingFetcher,
        private readonly TeachingSeancesFetcher $teachingFetcher,
        private readonly StudentClassesSeancesFetcher $studentFetcher,
    ) {}

    /**
     * Returns the upcoming séances payload (data + meta) for any role.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws MissingKlassciTokenException If the user has no `klassci_token`.
     */
    public function getUpcomingForUser(User $user, int $days, ?int $teacherId, ?int $classeId, ?TypeDeSeance $type = null): array
    {
        $klassciToken = $this->requireToken($user);

        $dateDebut = Carbon::now()->format('Y-m-d');
        $dateFin = Carbon::now()->addDays($days)->format('Y-m-d');

        $this->logger->info('Récupération séances à venir', [
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'teacher_id' => $teacherId,
            'classe_id' => $classeId,
        ]);

        // Depuis minuit : la séance terminée ce matin n'est plus « à venir » (FenetreVisio).
        // #1668 : le type se lit sur les cartes, qui portent `type_cours` repris, ou null.
        $seancesEnrichies = CartesDuType::garder(FenetreVisio::sansLesPassees(
            $this->upcomingFetcher->fetch($user, $klassciToken, $dateDebut, $dateFin, $teacherId, $classeId),
            Carbon::now(),
        ), $type);

        return [
            'data' => self::nonModifiables($seancesEnrichies),
            'meta' => [
                'total_seances' => count($seancesEnrichies),
                'date_debut' => $dateDebut,
                'date_fin' => $dateFin,
                'filtres' => [
                    'teacher_id' => $teacherId,
                    'classe_id' => $classeId,
                    'type' => $type?->value,
                ],
            ],
        ];
    }

    /**
     * Returns the séances the teacher is teaching.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws MissingKlassciTokenException If the user has no `klassci_token`.
     */
    public function getMyTeachingForUser(User $user): array
    {
        $klassciToken = $this->requireToken($user);

        [$dateDebut, $dateFin] = SeancesWindow::rolling();

        $seances = $this->teachingFetcher->fetch($user, $klassciToken, $dateDebut, $dateFin);

        return self::nonModifiables($seances->all());
    }

    /**
     * Returns the student's class séances payload.
     *
     * @return array{classe_missing: true}|array{empty_matieres: true}|array<int, array<string, mixed>>
     *
     * @throws MissingKlassciTokenException If the user has no `klassci_token`.
     */
    public function getMyClassesForUser(User $user): array
    {
        $klassciToken = $this->requireToken($user);

        [$dateDebut, $dateFin] = SeancesWindow::rolling();

        $seances = $this->studentFetcher->fetch($user, $klassciToken, $dateDebut, $dateFin);

        if ($seances === null) {
            return ['classe_missing' => true];
        }

        if ($seances === StudentClassesSeancesFetcher::RESULT_NO_MATIERES) {
            return ['empty_matieres' => true];
        }

        /** @var Collection<int, array<string, mixed>> $seances */
        return self::nonModifiables($seances->all());
    }

    /**
     * École KLASSCI (#1121) : une séance s'y modifie dans KLASSCI, le LMS la
     * récupère ; aucune carte ne se modifie ici ({@see KlassciModificationDeSeance}),
     * pas même une séance créée dans le LMS. Les clés sont gardées.
     *
     * @param  array<int, array<string, mixed>>  $cartes
     * @return array<int, array<string, mixed>>
     */
    private static function nonModifiables(array $cartes): array
    {
        // front#719 : l'appel non plus ne se fait pas ici, mais dans KLASSCI.
        return array_map(static fn (array $carte): array => array_merge($carte, ['can_be_edited' => false, 'can_take_attendance' => false]), $cartes);
    }

    /**
     * @throws MissingKlassciTokenException If the user has no `klassci_token`.
     */
    private function requireToken(User $user): string
    {
        $klassciToken = $user->klassci_token;

        if (! $klassciToken) {
            throw MissingKlassciTokenException::forUser($user->id);
        }

        return $klassciToken;
    }
}
