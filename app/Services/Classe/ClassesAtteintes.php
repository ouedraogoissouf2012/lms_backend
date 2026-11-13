<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\Classe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * « Les classes que ce compte atteint », dans une école créée depuis le LMS : la
 * grille d'accès aux classes, écrite UNE fois.
 *
 * | Appelant | Classes atteintes |
 * |---|---|
 * | gestion (coordinateur, admin, superAdmin) | toutes celles de l'établissement |
 * | enseignant | celles où il est désigné formateur ({@see ClassesEnseignees}) |
 * | apprenant, et tout rôle non reconnu (#941) | celles où son inscription est ACTIVE (D57) |
 *
 * Elle sert la fiche d'une classe ({@see LocalClasseDetailsReader}) et la liste
 * des classes de l'établissement ({@see LocalInstitutionClassesReader}). Elle ne
 * dit rien de la liste des élèves : celle-ci reste réservée au personnel
 * (GHSA-gg7j), par chaque lecteur.
 */
final class ClassesAtteintes
{
    /**
     * @param  Builder<Classe>  $classes  déjà bornée EXPLICITEMENT à l'établissement
     * @return Builder<Classe>
     */
    public static function par(Builder $classes, User $user, int $institution): Builder
    {
        if ($user->isManager()) {
            return $classes;
        }

        if ($user->isTeacher()) {
            return ClassesEnseignees::par($classes, $user->id, $institution);
        }

        return self::inscrites($classes, $user);
    }

    /**
     * Les classes où ce compte est inscrit, inscription ACTIVE (D57) : celles
     * d'un apprenant, quel que soit son rôle.
     *
     * @param  Builder<Classe>  $classes  déjà bornée EXPLICITEMENT à l'établissement
     * @return Builder<Classe>
     */
    public static function inscrites(Builder $classes, User $user): Builder
    {
        return $classes->whereHas('etudiants', static function ($inscrits) use ($user): void {
            $inscrits->where('users.id', $user->id)->where('classe_etudiant.statut', 'actif');
        });
    }
}
