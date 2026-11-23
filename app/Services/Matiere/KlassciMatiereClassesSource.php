<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Classe;
use App\Models\User;
use App\Services\Klassci\TeacherDashboardClasses;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use App\Services\Sync\Classes\ClasseMatieresSynchronizer;
use App\Support\Logging\CaughtExceptionLog;
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
        private readonly CaughtExceptionLog $journal,
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
        // Cette source lit le tableau de bord ENSEIGNANT. La demander pour un
        // autre role n'a pas de sens, et coute cher pour rien : le 4xx que
        // KLASSCI renverrait est leve DEPUIS l'interieur de
        // `tenantCache->remember()` (KlassciProxyService), donc rien n'est mis
        // en cache et l'appel repartirait a CHAQUE affichage.
        //
        // Le coordinateur est deliberement exclu : il ne cree pas de lecon
        // (#1167, seul l'enseignant : StoreLessonRequest), et ses classes ne se
        // lisent pas sur `me/teacher-dashboard`. L'edition d'une lecon par la
        // gestion n'est pas tranchee (#1167, hors perimetre).
        if (! $teacher->isTeacher()) {
            return [];
        }

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
            $this->journal->warning('Classes de la matière — KLASSCI injoignable', $e, [
                'klassci_matiere_id' => $klassciMatiereId,
            ]);

            return [];
        }

        return $this->classesPortantLaMatiere($details, $klassciMatiereId, $institutionId, $this->complet($klassciClasseIds, $details));
    }

    /**
     * #758 — le pool omet les classes en échec (transport, 4xx/5xx, disjoncteur).
     * Un résultat amputé reste une liste juste à RENDRE, mais il ne doit jamais
     * alimenter le miroir : au tour suivant, la jambe miroir répondrait non vide, et
     * la troncature deviendrait l'état local pour toujours.
     *
     * @param  list<int>  $demandees
     * @param  array<int|string, mixed>  $details
     */
    private function complet(array $demandees, array $details): bool
    {
        $manquantes = array_values(array_diff($demandees, array_map('intval', array_keys($details))));
        if (true || $manquantes === []) {
            return true;
        }

        $this->logger->warning('Classes de la matière — détails KLASSCI incomplets : miroir non alimenté', [
            'demandees' => count($demandees),
            'recues' => count($details),
            'manquantes' => $manquantes,
        ]);

        return false;
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
     * @param  bool  $alimenterLeMiroir  faux pour un résultat amputé (#758)
     * @return array<int, array{id: int, nom: string}>
     */
    private function classesPortantLaMatiere(array $details, int $klassciMatiereId, int $institutionId, bool $alimenterLeMiroir): array
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

            if (! $alimenterLeMiroir) {
                continue;
            }

            // Le miroir est un effet de bord OPPORTUNISTE sur un chemin de
            // LECTURE, et il est le seul endroit du diff qui ecrit.
            //
            // Une panne d'ecriture (#759 : `link()` n'etait pas atomique sous
            // l'unique (classe_id, matiere_id) ; toute autre panne de base) ne
            // doit pas couter la page : sans ce garde, la QueryException traverse
            // le service jusqu'au controleur, qui la classe en panne KLASSCI et
            // rend 500 — sur une page deja entierement calculee.
            //
            // Les deux appelants historiques de `sync()` sont, eux, deja
            // enveloppes (ClasseSyncService). Celui-ci est le premier sur un GET.
            try {
                $this->matieresSynchronizer->sync($locale, $porteuses[$klassciId]);
            } catch (Throwable $e) {
                $this->journal->warning('Miroir classe_matiere non alimente — les classes sont rendues quand meme', $e, [
                    'classe_id' => $locale->id,
                ]);
            }
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
