<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Matiere;
use App\Models\User;

/**
 * MatiereDetailsQueryService — orchestrates the matière details payload.
 *
 * Replaces the inline pipeline that used to live in
 * {@see app/Http/Controllers/API/LMS/LMSMatieresQueryController.php::matiereDetails}
 * (legacy 472-line method).
 *
 * Pipeline:
 *   1. Resolve matière + combinaisons + enseignants ({@see MatiereInfoFetcher}).
 *   2. Resolve role-scoped séances + enrich them ({@see MatiereSeancesFetcher}).
 *   3. Fetch evaluations (KLASSCI + LMS-only) ({@see MatiereEvaluationsFetcher}).
 *   4. Fetch lessons + compute stats ({@see MatiereLessonsAndStatsBuilder}).
 *
 * Contract preserved from the original controller:
 *   - Missing `klassci_token` → throws `MissingKlassciTokenException` (caller renders 401).
 *   - KLASSCI answers an error → `RuntimeException` porting ITS status, relayed as-is.
 *   - Matière introuvable → returns `null` (caller renders 404).
 *   - Returns the final `data` array on success.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (services ≤300 lignes)
 * @see PRODUCTION_STANDARDS.md §1.6 D (DI strict)
 */
final class MatiereDetailsQueryService
{
    public function __construct(
        private readonly MatiereInfoFetcher $infoFetcher,
        private readonly MatiereSeancesFetcher $seancesFetcher,
        private readonly MatiereEvaluationsFetcher $evaluationsFetcher,
        private readonly MatiereLessonsAndStatsBuilder $lessonsAndStatsBuilder,
        private readonly MatiereClassesResolver $classesResolver,
    ) {}

    /**
     * @return array{matiere: array<string, mixed>, matiere_id_local: int|null, combinaisons: array<int, array<string, mixed>>, enseignants: array<int, array<string, mixed>>, lessons: array<int, array<string, mixed>>, seances_programmees: array<int, array<string, mixed>>, evaluations_programmees: array<int, array<string, mixed>>, classes_concernees: array<int, array{id: int, nom: string}>, statistiques: array<string, mixed>}|null
     */
    public function getDetailsForUser(int $matiereId, User $user): ?array
    {
        $klassciToken = $user->klassci_token;

        if (! $klassciToken) {
            throw MissingKlassciTokenException::forUser($user->id);
        }

        // 1. Base matière info + combinaisons + enseignants.
        $info = $this->infoFetcher->fetchMatiereInfo($klassciToken, $matiereId);

        if ($info === null) {
            return null;
        }

        // 2. Séances (role-scoped + filtered + enriched).
        $seancesPayload = $this->seancesFetcher->fetchSeancesForUser(
            $user,
            $matiereId,
            $klassciToken,
            $info['matiereData'],
        );

        // 3. Evaluations (KLASSCI + LMS-only). Reutilise le payload de l'etape 1 —
        // §1.4 : matieres/{id} porte deja les evaluations, aucun second appel.
        $evaluationsPayload = $this->evaluationsFetcher->fetchEvaluationsForMatiere(
            $info['matiereData'],
            $matiereId,
            $user,
        );

        // 4. Lessons + stats aggregate.
        $lessonsAndStats = $this->lessonsAndStatsBuilder->buildLessonsAndStats(
            $matiereId,
            $user,
            $seancesPayload['seances'],
            $info['matiere'],
            $info['combinaisons'],
            $info['enseignants'],
            $evaluationsPayload['evaluations_raw_count'],
        );

        return [
            'matiere' => $info['matiere'],
            'combinaisons' => $info['combinaisons'],
            'enseignants' => $info['enseignants'],
            'lessons' => $lessonsAndStats['lessons'],
            'seances_programmees' => $seancesPayload['seances_enrichies'],
            'evaluations_programmees' => $evaluationsPayload['evaluations_enrichies'],
            'statistiques' => $lessonsAndStats['stats'],
        ] + $this->localContext($matiereId, $user, $seancesPayload['seances_enrichies']);
    }

    /**
     * Ce que le LMS sait LOCALEMENT de cette matière : son identité dans notre
     * espace, et les classes qui lui sont rattachées.
     *
     * Les deux champs répondent à la même question — « de quoi le frontend
     * a-t-il besoin pour AGIR sur cette matière chez nous ? » — et tous deux
     * sont exprimés dans l'espace LOCAL, celui que nos colonnes stockent. Le
     * reste de la réponse est du passthrough KLASSCI ; ce bloc-ci est notre
     * part.
     *
     * **`matiere_id_local`** — `matiere` porte l'identifiant KLASSCI, celui de
     * la route, et le frontend le renvoyait tel quel à la création de leçon.
     * Or `lessons.matiere_id` est une clé LOCALE : sur une collision entre les
     * deux numérotations, la leçon partait sur une AUTRE matière, en 201 et
     * sans erreur. Mesuré le 2026-09-07 — une leçon créée depuis « Anglais »
     * (KLASSCI 3) s'est retrouvée sur « Algorithme » (local 3), invisible sur
     * la page d'origine. `null` quand la matière n'est pas miroitée : le
     * frontend omet alors le champ. Une leçon sans matière est réparable, une
     * leçon sur la mauvaise matière est une corruption silencieuse.
     *
     * **`classes_concernees`** — #740, deux sources : les séances (immédiat) ET
     * le miroir local `classe_matiere`. Sans la seconde, une matière SANS
     * séance n'avait aucune classe, donc `classe_id: null` côté frontend, donc
     * 403 à la création de leçon — sur une matière parfaitement légitime.
     *
     * @param  array<int, array<string, mixed>>  $seancesEnrichies
     * @return array{matiere_id_local: int|null, classes_concernees: array<int, array{id: int, nom: string}>}
     */
    private function localContext(int $matiereId, User $user, array $seancesEnrichies): array
    {
        return [
            'matiere_id_local' => Matiere::localIdForKlassciId($matiereId, $user->institution_id),
            'classes_concernees' => $this->classesResolver->resolve(
                $seancesEnrichies,
                $matiereId,
                $user->institution_id,
                $user,
            ),
        ];
    }
}
