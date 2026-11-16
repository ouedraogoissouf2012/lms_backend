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
use UnexpectedValueException;

/**
 * Les classes que KLASSCI rattache à une matière, parmi celles de l'enseignant
 * ou, pour un gestionnaire, de l'établissement (#757).
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
     * Les classes de l'enseignant (ou, pour un gestionnaire, de l'établissement)
     * qui portent cette matière, en identifiants LOCAUX.
     *
     * Coût : 1 appel (tableau de bord, ou liste de l'établissement) + N détails de
     * classe groupés, sous le délai global d'une lecture KLASSCI. C'est la raison
     * pour laquelle le résolveur ne l'appelle qu'en dernier recours. Un
     * gestionnaire n'en paie le coût qu'une fois : le miroir répond ensuite.
     *
     * @return array<int, array{id: int, nom: string}>
     */
    public function classesFor(User $user, int $klassciMatiereId, int $institutionId): array
    {
        $klassciToken = $user->klassci_token;

        if (! $this->lit($user) || ! is_string($klassciToken) || $klassciToken === '') {
            return [];
        }

        try {
            $klassciClasseIds = $this->klassciClasseIdsOf($user, $klassciToken);

            if ($klassciClasseIds === []) {
                return [];
            }

            // Sous le délai GLOBAL d'une lecture KLASSCI : pour un gestionnaire,
            // N vaut les classes de l'établissement entier (#757).
            $details = $this->klassciService->fetchManyClassesDetailsWithinTimeout($klassciClasseIds, $klassciToken);
        } catch (Throwable $e) {
            // Jamais une erreur remontée : l'écran affiche « aucune classe »,
            // pas une page en 500.
            $this->journal->warning('Classes de la matière — KLASSCI injoignable', $e, [
                'klassci_matiere_id' => $klassciMatiereId,
            ]);

            return [];
        }

        return $this->classesPortantLaMatiere($details, $klassciMatiereId, $institutionId, $this->complet($details, $klassciClasseIds, $klassciMatiereId));
    }

    /**
     * Qui a une source de classes : l'enseignant, et le gestionnaire (coordinateur,
     * admin d'école), qui a le droit de créer une leçon (`StoreLessonRequest`,
     * `isManager()`, #757). Pas l'apprenant : la page ne lui propose pas de créer.
     */
    private function lit(User $user): bool
    {
        return $user->isTeacher();
    }

    /**
     * Les identifiants KLASSCI des classes à interroger — un seul appel.
     *
     * - l'enseignant : celles de son tableau de bord ;
     * - le gestionnaire : celles de l'établissement (`classes`, jeton
     *   d'établissement, l'appel de l'écran de gestion des classes). JAMAIS
     *   `me/teacher-dashboard` avec son jeton : le 4xx de KLASSCI serait levé dans
     *   `tenantCache->remember()`, rien ne serait mis en cache, et l'appel
     *   repartirait à chaque affichage.
     *
     * @return list<int>
     */
    private function klassciClasseIdsOf(User $user, string $klassciToken): array
    {
        $classes = $user->isTeacher()
            ? $this->dashboardClasses->forToken($klassciToken)
            : $this->classesDeLEtablissement();

        return KlassciPayload::uniqueIntIds(
            $classes,
            static fn (array $classe): ?int => KlassciPayload::toInt($classe['id'] ?? null),
        );
    }

    /**
     * La liste des classes de l'établissement, telle que KLASSCI la rend
     * (`data`, une liste) ; toute autre forme lève, et la jambe rend « aucune
     * classe » au lieu de deviner.
     *
     * @return list<array<string, mixed>>
     */
    private function classesDeLEtablissement(): array
    {
        $donnees = $this->klassciService->getClasses()['data'] ?? null;

        if (! is_array($donnees) || ! array_is_list($donnees)) {
            throw new UnexpectedValueException('Réponse KLASSCI inattendue pour `classes` : liste attendue.');
        }

        return KlassciPayload::listOfArrays($donnees);
    }

    /**
     * Le pool a-t-il rendu TOUTES les classes demandées ? Un identifiant absent
     * est une classe en échec, ou non lue avant l'échéance.
     *
     * Un partiel est rendu (la liste est incomplète, jamais fausse) mais il
     * n'alimente pas le miroir : la deuxième jambe répondrait alors non vide, et
     * la troisième ne rejouerait plus jamais. Le partiel deviendrait l'état
     * stable (#758).
     *
     * @param  array<int|string, mixed>  $details
     * @param  list<int>  $demandes
     */
    private function complet(array $details, array $demandes, int $klassciMatiereId): bool
    {
        $manquantes = count(array_diff($demandes, array_map('intval', array_keys($details))));

        if ($manquantes > 0) {
            $this->logger->warning('Classes de la matière — détails partiels : miroir non alimenté', [
                'klassci_matiere_id' => $klassciMatiereId,
                'demandees' => count($demandes),
                'manquantes' => $manquantes,
            ]);
        }

        return $manquantes === 0;
    }

    /**
     * Parmi les détails récupérés, celles qui portent la matière — traduites
     * vers l'espace LOCAL, seul admis par `lessons.classe_id`.
     *
     * Une classe que KLASSCI cite mais que le miroir ne connaît pas est
     * écartée : `StoreLessonRequest::authorize()` la refuserait de toute façon,
     * et proposer une classe inutilisable, c'est proposer une erreur.
     *
     * Le miroir n'est alimenté que d'une réponse COMPLÈTE (#758, voir `complet()`).
     *
     * @param  array<int|string, mixed>  $details
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
            // `ClasseMatieresSynchronizer::link()` fait `exists()` puis
            // `insert()` sans atomicite, sous un unique (classe_id, matiere_id) :
            // deux requetes concurrentes levent 1062 ou 1213. Sans ce garde, la
            // QueryException traverse le service jusqu'au controleur, qui la
            // classe en panne KLASSCI et rend 500 — sur une page deja
            // entierement calculee, et sans meme un log d'erreur.
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
