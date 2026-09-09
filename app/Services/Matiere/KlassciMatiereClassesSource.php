<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Classe;
use App\Models\User;
use App\Services\Klassci\TeacherDashboardClasses;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use App\Services\Sync\Classes\ClasseMatieresSynchronizer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Les classes que KLASSCI rattache à une matière, parmi celles de l'enseignant.
 *
 * ## Pourquoi cette source existe
 *
 * `MatiereClassesResolver` avait deux jambes, toutes deux LOCALES : les séances
 * de la matière, et le miroir `classe_matiere`. Elles peuvent être muettes
 * ENSEMBLE — une matière sans séance dont le miroir n'a jamais été alimenté.
 *
 * Mesure du 2026-09-09 en production : « Anglais » rendait
 * `classes_concernees = []`, le frontend envoyait donc `classe_id: null`, et
 * `StoreLessonRequest::authorize()` refusait — **403** sur une matière
 * parfaitement légitime. Les autres matières ne passaient que parce qu'elles
 * ont des séances.
 *
 * ## La source est exacte, pas déduite
 *
 * KLASSCI AFFIRME quelles matières porte chaque classe — mesure du même jour :
 *
 *     classe 1 (B2 COM) -> matieres [3, 1, 2]
 *     classe 2 (B3 COM) -> matieres []
 *     classe 4 (BTS)    -> matieres [3, 1, 2]
 *
 * Une autre piste existait : croiser les `combinaisons` (filière × niveau) de
 * la matière avec celles des classes de l'enseignant. Elle donnait le même
 * résultat sur ce jeu de données, mais par DÉDUCTION — « il a une classe dans
 * cette filière, donc il y enseigne cette matière ». Sur une donnée qui finit
 * dans `lessons.classe_id`, seul verrou de visibilité étudiante, on prend ce
 * que KLASSCI affirme, jamais ce qu'on infère.
 *
 * ## Le miroir est alimenté au passage
 *
 * Chaque classe visitée reverse ses matières dans `classe_matiere`. Le mode
 * dégradé — la deuxième jambe du résolveur — aura enfin de quoi répondre quand
 * KLASSCI sera injoignable, sans qu'aucun job de sync n'ait à tourner.
 *
 * Vérifié par tests/Feature/Matiere/MatiereClassesFromKlassciTest.php.
 */
final class KlassciMatiereClassesSource
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly TeacherDashboardClasses $dashboardClasses,
        private readonly ClasseMatieresSynchronizer $matieresSynchronizer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Les classes de l'enseignant qui portent cette matière, en identifiants
     * LOCAUX.
     *
     * Coût : 1 appel (tableau de bord) + N détails de classe groupés. C'est la
     * raison pour laquelle le résolveur ne l'appelle qu'en dernier recours.
     *
     * @return array<int, array{id: int, nom: string}>
     */
    public function classesFor(User $teacher, int $klassciMatiereId, int $institutionId): array
    {
        $klassciToken = $teacher->klassci_token;

        if (! is_string($klassciToken) || $klassciToken === '') {
            return [];
        }

        try {
            $klassciClasseIds = $this->klassciClasseIdsOf($klassciToken);

            if ($klassciClasseIds === []) {
                return [];
            }

            $details = $this->klassciService->fetchManyClassesDetails($klassciClasseIds, $klassciToken);
        } catch (Throwable $e) {
            // Jamais une erreur remontée : l'écran affiche « aucune classe »,
            // pas une page en 500.
            $this->logger->warning('Classes de la matière — KLASSCI injoignable', [
                'klassci_matiere_id' => $klassciMatiereId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $this->classesPortantLaMatiere($details, $klassciMatiereId, $institutionId);
    }

    /**
     * Les identifiants KLASSCI des classes de l'enseignant, depuis son tableau
     * de bord — un seul appel.
     *
     * @return list<int>
     */
    private function klassciClasseIdsOf(string $klassciToken): array
    {
        return KlassciPayload::uniqueIntIds(
            $this->dashboardClasses->forToken($klassciToken),
            static fn (array $classe): ?int => KlassciPayload::toInt($classe['id'] ?? null),
        );
    }

    /**
     * Parmi les détails récupérés, celles qui portent la matière — traduites
     * vers l'espace LOCAL, seul admis par `lessons.classe_id`.
     *
     * Une classe que KLASSCI cite mais que le miroir ne connaît pas est
     * écartée : `StoreLessonRequest::authorize()` la refuserait de toute façon,
     * et proposer une classe inutilisable, c'est proposer une erreur.
     *
     * @param  array<int|string, mixed>  $details
     * @return array<int, array{id: int, nom: string}>
     */
    private function classesPortantLaMatiere(array $details, int $klassciMatiereId, int $institutionId): array
    {
        $porteuses = [];

        foreach ($details as $klassciClasseId => $detail) {
            $matieres = KlassciPayload::listOfArrays(
                KlassciPayload::asArray(is_array($detail) ? ($detail['data'] ?? null) : null)['matieres'] ?? null,
            );

            if ($this->porteLaMatiere($matieres, $klassciMatiereId)) {
                $porteuses[(int) $klassciClasseId] = $matieres;
            }
        }

        $classes = [];

        // UNE requête pour toutes les classes, pas une par tour de boucle : la
        // traduction vers l'espace local est un `whereIn`, pas un N+1.
        foreach ($this->localesParKlassciId(array_keys($porteuses), $institutionId) as $klassciId => $locale) {
            $classes[] = ['id' => (int) $locale->id, 'nom' => (string) ($locale->libelle ?? 'N/A')];
            $this->matieresSynchronizer->sync($locale, $porteuses[$klassciId]);
        }

        return $classes;
    }

    /**
     * Les classes locales de l'institution, indexées par `klassci_id`.
     *
     * @param  list<int>  $klassciIds
     * @return array<int, Classe>
     */
    private function localesParKlassciId(array $klassciIds, int $institutionId): array
    {
        if ($klassciIds === []) {
            return [];
        }

        $locales = [];

        foreach (Classe::query()
            ->where('institution_id', $institutionId)
            ->whereIn('klassci_id', $klassciIds)
            ->orderBy('id')
            ->get() as $classe) {
            $klassciId = $classe->klassci_id;

            // Premier gagnant : `klassci_id` n'est unique que par institution,
            // et l'ordre par `id` rend l'arbitrage déterministe.
            if (is_numeric($klassciId) && ! isset($locales[(int) $klassciId])) {
                $locales[(int) $klassciId] = $classe;
            }
        }

        return $locales;
    }

    /**
     * @param  array<int, array<string, mixed>>  $matieres
     */
    private function porteLaMatiere(array $matieres, int $klassciMatiereId): bool
    {
        foreach ($matieres as $matiere) {
            if (KlassciPayload::toInt($matiere['id'] ?? null) === $klassciMatiereId) {
                return true;
            }
        }

        return false;
    }
}
