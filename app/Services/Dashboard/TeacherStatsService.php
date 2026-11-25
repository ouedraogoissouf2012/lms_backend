<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Lesson;
use App\Models\User;
use App\Services\Roster\RosterAuthorityFactory;

/**
 * Compteurs de contenu d'un enseignant : matières, classes, évaluations, leçons (#364, #1624).
 *
 * ## Le défaut corrigé (#1624)
 *
 * Tout était rapporté à l'identité KLASSCI du compte : un enseignant qui n'en a pas (tout
 * enseignant d'une école autonome) voyait quatre zéros, quoi qu'il ait créé. Et les leçons étaient
 * filtrées sur `enseignant_id` = identité KLASSCI, alors que la colonne porte l'id LOCAL de
 * l'auteur (`LessonCrudOperationsService`), ce qui faussait aussi le volet KLASSCI.
 *
 * ## La règle
 *
 * - Les leçons : `enseignant_id` = id du compte, dans les deux volets (c'est la même colonne).
 * - Les évaluations, et les classes et matières qu'elles touchent : selon le volet de l'école,
 *   choisi au seul {@see RosterAuthorityFactory} ({@see ContenusEvaluesDeLEnseignant}).
 * - Classes et matières : l'union, dans l'espace des ids LOCAUX, de celles des leçons et de celles
 *   des évaluations. Chaque requête borne l'école explicitement, sans dépendre du tenant résolu.
 *
 * @see app/Http/Controllers/API/TeacherStatsController.php
 */
final class TeacherStatsService
{
    public function __construct(private readonly RosterAuthorityFactory $autorites) {}

    /**
     * @return array{matieres: int, classes: int, evaluations: int, lessons: int}
     */
    public function buildStats(User $user): array
    {
        if (! is_int($user->institution_id)) {
            return ['matieres' => 0, 'classes' => 0, 'evaluations' => 0, 'lessons' => 0];
        }

        $ecole = $user->institution_id;
        $lecons = Lesson::query()
            ->withoutGlobalScope('institution')
            ->where('institution_id', $ecole)
            ->where('enseignant_id', $user->klassci_enseignant_id)
            ->get(['classe_id', 'matiere_id']);

        /** @var ContenusEvaluesDeLEnseignant $source */
        $source = $this->autorites->sourceForAccount($user, ContenusEvaluesKlassci::class, ContenusEvaluesLocaux::class);
        $evalues = $source->de($user, $ecole);

        return [
            'matieres' => count(IdsLocaux::de([...$lecons->pluck('matiere_id')->all(), ...$evalues['matieres']])),
            'classes' => count(IdsLocaux::de([...$lecons->pluck('classe_id')->all(), ...$evalues['classes']])),
            'evaluations' => $evalues['evaluations'],
            'lessons' => $lecons->count(),
        ];
    }
}
