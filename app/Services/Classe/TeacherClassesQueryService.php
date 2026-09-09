<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;
use App\Models\User;
use App\Services\Enrollment\EnrollmentSource;
use App\Services\Klassci\TeacherDashboardClasses;
use App\Services\Seances\KlassciPayload;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Les classes d'un enseignant, pour l'écran « Mes Classes » (#712).
 *
 * ## Deux défauts corrigés, mesurés en production le 2026-09-09
 *
 * **1. La source était vide, et le restait.** Ce service ne lisait que les
 * miroirs locaux `matiere_enseignant` et `classe_matiere` — tous deux à 0 ligne
 * — pendant que le tableau de bord affichait 4 classes. Ces miroirs ne se
 * remplissent que si l'enseignant visite une page précise : en dépendre pour
 * l'affichage rendait l'écran fonction du parcours de navigation.
 *
 * **2. Et même remplis, l'écran aurait été faux.** Le service rendait l'id
 * LOCAL, alors que le frontend fusionne cette réponse avec le référentiel
 * `/proxy/classes` — du KLASSCI — par `id`. L'appariement n'aurait jamais eu
 * lieu, et les effectifs seraient restés à « — ». Ce défaut était invisible
 * tant que la liste était vide.
 *
 * ## L'ordre des sources est inversé, délibérément
 *
 * KLASSCI d'abord, miroir en repli. Un miroir n'a de valeur que s'il est
 * alimenté ; en faire la source unique d'un écran alors que rien ne le
 * remplit de façon fiable, c'est promettre une donnée qu'on n'a pas.
 *
 * `me/teacher-dashboard` rend les classes de l'enseignant en UN appel
 * authentifié — donc sur un chemin où `KlassciConfigResolver` résout l'URL par
 * le jeton du porteur (priorité 1). Mesure en production : 4 classes rendues,
 * exactement celles du tableau de bord.
 *
 * Le miroir garde son rôle : répondre quand KLASSCI ne répond plus. On dégrade,
 * on ne vide pas.
 *
 * ## L'espace d'identifiants rendu est celui de KLASSCI
 *
 * Ce n'est pas une contradiction avec #740, qui impose le LOCAL pour
 * `classes_concernees`. Là-bas, la valeur est STOCKÉE dans `lessons.classe_id`.
 * Ici rien n'est stocké : l'identifiant sert à fusionner avec KLASSCI et à
 * naviguer vers lui. La règle est la même dans les deux cas — **on émet
 * l'espace que le consommateur consomme**.
 *
 * Vérifié par tests/Feature/Classe/TeacherClassesLiveSourceTest.php.
 */
final class TeacherClassesQueryService
{
    public function __construct(
        private readonly EnrollmentSource $enrollment,
        private readonly TeacherDashboardClasses $dashboardClasses,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<array{id: int, libelle: string|null, code: string|null, effectif: int|null, effectif_actuel: int|null}>
     */
    public function listFor(User $teacher): array
    {
        $live = $this->fromKlassci($teacher);

        return $live !== [] ? $live : $this->fromLocalMirror($teacher);
    }

    /**
     * Les classes que KLASSCI reconnaît à cet enseignant, enrichies de
     * l'effectif local quand la classe est miroitée.
     *
     * Une classe absente du miroir reste dans la liste, avec des effectifs
     * `null` : « je ne sais pas » et « zéro étudiant » sont deux faits
     * différents, et le frontend rend le premier par « — ».
     *
     * @return list<array{id: int, libelle: string|null, code: string|null, effectif: int|null, effectif_actuel: int|null}>
     */
    private function fromKlassci(User $teacher): array
    {
        $klassciToken = $teacher->klassci_token;

        if (! is_string($klassciToken) || $klassciToken === '') {
            return [];
        }

        try {
            $classes = $this->dashboardClasses->forToken($klassciToken);
        } catch (Throwable $e) {
            // Dégrader, pas vider : le miroir prend le relais.
            $this->logger->warning('Classes enseignant — KLASSCI injoignable, repli sur le miroir local', [
                'user_id' => $teacher->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $locales = $this->localesParKlassciId($teacher, $classes);
        $rows = [];

        foreach ($classes as $classe) {
            $klassciId = KlassciPayload::toInt($classe['id'] ?? null);

            if ($klassciId === null) {
                continue;
            }

            $rows[] = $this->row($klassciId, $this->nom($classe), $locales[$klassciId] ?? null);
        }

        return $this->triesParLibelle($rows);
    }

    /**
     * L'ordre est un CONTRAT : la liste est rendue en cartes, et suivre l'ordre
     * de KLASSCI ferait sauter les cartes d'un chargement à l'autre. Le tri par
     * libellé reprend le comportement antérieur (`orderBy('libelle')`).
     *
     * @param  list<array{id: int, libelle: string|null, code: string|null, effectif: int|null, effectif_actuel: int|null}>  $rows
     * @return list<array{id: int, libelle: string|null, code: string|null, effectif: int|null, effectif_actuel: int|null}>
     */
    private function triesParLibelle(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcasecmp((string) $a['libelle'], (string) $b['libelle']));

        return $rows;
    }

    /**
     * Le repli : les classes déduites des miroirs locaux, rendues dans le MÊME
     * espace d'identifiants que la source vivante — sans quoi le frontend
     * n'apparierait plus rien dès que KLASSCI tombe.
     *
     * @return list<array{id: int, libelle: string|null, code: string|null, effectif: int|null, effectif_actuel: int|null}>
     */
    private function fromLocalMirror(User $teacher): array
    {
        $ids = $this->enrollment->classeIdsForTeacher($teacher);

        if ($ids === []) {
            return [];
        }

        $rows = [];

        foreach ($this->classesQuery()->whereIn('id', $ids)->orderBy('libelle')->get() as $classe) {
            $klassciId = KlassciPayload::toInt($classe->klassci_id);

            if ($klassciId !== null) {
                $rows[] = $this->row($klassciId, $classe->libelle, $classe);
            }
        }

        return $rows;
    }

    /**
     * Les lignes locales des classes citées, indexées par `klassci_id` et
     * bornées à l'institution de l'enseignant — le `klassci_id` n'est unique
     * QUE par établissement (#707).
     *
     * @param  array<int, array<string, mixed>>  $classes
     * @return array<int, Classe>
     */
    private function localesParKlassciId(User $teacher, array $classes): array
    {
        $klassciIds = KlassciPayload::uniqueIntIds(
            $classes,
            static fn (array $classe): ?int => KlassciPayload::toInt($classe['id'] ?? null),
        );

        if ($klassciIds === [] || $teacher->institution_id === null) {
            return [];
        }

        $parKlassciId = [];

        foreach (
            $this->classesQuery()
                ->where('institution_id', $teacher->institution_id)
                ->whereIn('klassci_id', $klassciIds)
                ->get() as $classe
        ) {
            $cle = KlassciPayload::toInt($classe->klassci_id);

            if ($cle !== null) {
                $parKlassciId[$cle] = $classe;
            }
        }

        return $parKlassciId;
    }

    /**
     * @return Builder<Classe>
     */
    private function classesQuery(): Builder
    {
        return Classe::query()->withCount(['etudiantsActifs as effectif_actuel']);
    }

    /**
     * @return array{id: int, libelle: string|null, code: string|null, effectif: int|null, effectif_actuel: int|null}
     */
    private function row(int $klassciId, ?string $libelle, ?Classe $locale): array
    {
        $actuel = $locale?->getAttributes()['effectif_actuel'] ?? null;

        return [
            'id' => $klassciId,
            'libelle' => $libelle ?? $locale?->libelle,
            'code' => $locale?->code,
            'effectif' => is_numeric($locale?->effectif) ? (int) $locale->effectif : null,
            'effectif_actuel' => is_numeric($actuel) ? (int) $actuel : null,
        ];
    }

    /**
     * KLASSCI nomme la classe tantôt `name`, tantôt `libelle`, tantôt `nom`.
     *
     * @param  array<string, mixed>  $classe
     */
    private function nom(array $classe): ?string
    {
        foreach (['name', 'libelle', 'nom'] as $cle) {
            if (is_string($classe[$cle] ?? null) && $classe[$cle] !== '') {
                return $classe[$cle];
            }
        }

        return null;
    }
}
