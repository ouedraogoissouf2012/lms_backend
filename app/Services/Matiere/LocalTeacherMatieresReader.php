<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Enums\LessonStatus;
use App\Models\Classe;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Mes matières », lues dans le programme LOCAL de l'établissement.
 *
 * ## Les matières de l'APPELANT
 *
 * Une matière est à lui quand il y est désigné formateur dans au moins une
 * classe (`classe_matiere.enseignant_id`, écrit par `LocalClasseMatiereLinker`).
 * C'est le sens de la source KLASSCI, qui interroge `me/teacher-dashboard` avec
 * le jeton PERSONNEL : elle rend ce qui est attribué à cet appelant, jamais tout
 * l'établissement. `LocalClasseMatiereLinker` ne désigne qu'un compte de rôle
 * enseignant : un coordinateur ou un superAdmin, que la route admet, reçoit
 * donc toujours une liste vide. Le catalogue entier se lit ailleurs.
 *
 * Les compteurs ne portent que sur les leçons de l'appelant, comme sa page
 * matière (D73).
 *
 * ## Les clés sont celles que le front lit
 *
 * `MatiereCourseCard.vue` lit `id`, `nom`, `coefficient`, `classes[].{id, nom}`
 * et quatre compteurs. Rien de plus n'est émis : une clé que personne ne lit
 * est une promesse que personne ne vérifie.
 *
 * ## Deux compteurs ne sont pas des zéros
 *
 * - `nombre_seances` vaut `null` : une séance locale ne porte que le NOM de sa
 *   matière, en texte (`LocalSeanceCreator`). Compter par ce texte devinerait ;
 *   rendre 0 affirmerait. On dit « inconnu ».
 * - `nombre_evaluations` vaut 0, et c'est un fait : aucune évaluation n'existe
 *   encore dans un établissement sans KLASSCI (provisoire, D72).
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
final class LocalTeacherMatieresReader implements TeacherMatieresReader
{
    /**
     * @return list<array{id: int, nom: string, coefficient: int, classes: list<array{id: int, nom: string}>, statistiques: array{nombre_lessons_publiees: int, nombre_lessons_brouillons: int, nombre_seances: null, nombre_evaluations: int}}>
     */
    public function getMatieresForUser(User $user): array
    {
        $institution = $user->institution_id;

        if (! is_int($institution)) {
            return [];
        }

        $matieres = $this->matieresEnseignees($user, $institution);
        $ids = array_map(static fn (Matiere $matiere): int => $matiere->id, $matieres);
        // Les leçons de L'APPELANT seulement : sa page matière ne lui montre
        // que les siennes (D73). Compter celles des collègues contredirait la
        // page, et révélerait combien de brouillons ils ont.
        $siennes = Lesson::query()->where('enseignant_id', $user->id);
        $publiees = $this->compteParMatiere((clone $siennes)->published(), $ids, $institution);
        $brouillons = $this->compteParMatiere(
            (clone $siennes)->where('status', LessonStatus::Draft->value),
            $ids,
            $institution,
        );

        return array_map(static fn (Matiere $matiere): array => [
            'id' => $matiere->id,
            'nom' => $matiere->libelle,
            'coefficient' => $matiere->coefficient,
            'classes' => array_values(array_map(static fn (Classe $classe): array => [
                'id' => $classe->id,
                'nom' => $classe->libelle,
            ], $matiere->classes->all())),
            'statistiques' => [
                'nombre_lessons_publiees' => $publiees[$matiere->id] ?? 0,
                'nombre_lessons_brouillons' => $brouillons[$matiere->id] ?? 0,
                'nombre_seances' => null,
                'nombre_evaluations' => 0,
            ],
        ], $matieres);
    }

    /**
     * Les matières où l'appelant est désigné, avec les classes où il les
     * enseigne.
     *
     * @return list<Matiere>
     */
    private function matieresEnseignees(User $user, int $institution): array
    {
        // Bornage EXPLICITE à l'établissement, sur la matière comme sur la
        // classe : le scope global est fail-open sans tenant résolu. Le même
        // filtre sert au `whereHas` et au chargement des classes — deux copies
        // finiraient par diverger, et une matière s'afficherait avec les
        // classes d'un collègue.
        $enseigneesParLui = static function ($classes) use ($user, $institution): void {
            $classes->where('classe_matiere.enseignant_id', $user->id)
                ->where('classes.institution_id', $institution);
        };

        return array_values(Matiere::query()
            ->where('institution_id', $institution)
            ->whereHas('classes', $enseigneesParLui)
            ->with(['classes' => static function ($classes) use ($enseigneesParLui): void {
                $enseigneesParLui($classes);
                $classes->orderBy('classes.libelle')->orderBy('classes.id');
            }])
            ->orderBy('libelle')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * Un compte par matière en UNE requête, quel que soit leur nombre (même
     * geste que `MyMatieresQueryService::preloadStats`, #546).
     *
     * @param  Builder<Lesson>  $lessons
     * @param  list<int>  $matiereIds
     * @return array<int, int>
     */
    private function compteParMatiere(Builder $lessons, array $matiereIds, int $institution): array
    {
        if ($matiereIds === []) {
            return [];
        }

        $comptes = [];

        foreach (
            $lessons->where('institution_id', $institution)
                ->whereIn('matiere_id', $matiereIds)
                ->select('matiere_id')
                ->selectRaw('COUNT(*) as cnt')
                ->groupBy('matiere_id')
                ->get() as $ligne
        ) {
            $nombre = $ligne->getAttribute('cnt');

            if (is_int($ligne->matiere_id) && is_numeric($nombre)) {
                $comptes[$ligne->matiere_id] = (int) $nombre;
            }
        }

        return $comptes;
    }
}
