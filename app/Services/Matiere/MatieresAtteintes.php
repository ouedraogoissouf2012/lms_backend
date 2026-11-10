<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Matiere;
use App\Models\User;
use App\Services\Classe\ClassesAtteintes;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Les matières que ce compte atteint », dans une école créée depuis le LMS, et
 * les classes où il les atteint (lot 3c).
 *
 * | Appelant | Matières | Classes de chaque matière |
 * |---|---|---|
 * | gestion (coordinateur, admin, superAdmin) | tout le catalogue, même hors programme | toutes celles qui l'ont au programme |
 * | enseignant | celles où il est désigné formateur | celles où il l'enseigne |
 * | apprenant, et tout rôle non reconnu (#941) | celles d'une classe où son inscription est ACTIVE (D57) | ces classes-là |
 *
 * La lecture du rôle et de la classe est celle de {@see ClassesAtteintes}, écrite
 * une fois. Pour l'enseignant, elle y ajoute la désignation sur CETTE matière :
 * une classe où il enseigne une autre matière n'est pas à lui pour celle-ci.
 *
 * C'est la grille de la page d'une matière ({@see LocalMatiereDetailsReader}).
 * « Mes matières » ({@see LocalTeacherMatieresReader}) en diffère à dessein : elle
 * ne rend à la gestion que les matières qu'elle enseigne, donc aucune.
 */
final class MatieresAtteintes
{
    /**
     * @param  Builder<Matiere>  $matieres  déjà bornée EXPLICITEMENT à l'établissement
     * @return Builder<Matiere>
     */
    public static function par(Builder $matieres, User $user, int $institution): Builder
    {
        if ($user->isManager()) {
            return $matieres;
        }

        return $matieres->whereHas('classes', static function ($classes) use ($user, $institution): void {
            self::classesAtteintes($classes, $user, $institution);
        });
    }

    /**
     * Restreint les classes d'une matière (la relation `classes`, sur le pivot
     * `classe_matiere`) à celles où ce compte l'atteint, bornées explicitement
     * à l'établissement : le scope global est fail-open sans tenant.
     *
     * @param  Builder<\App\Models\Classe>  $classes
     */
    public static function classesAtteintes(Builder $classes, User $user, int $institution): void
    {
        $classes->where('classes.institution_id', $institution);

        if ($user->isTeacher()) {
            $classes->where('classe_matiere.enseignant_id', $user->id);
        }

        ClassesAtteintes::par($classes, $user, $institution);
    }
}
