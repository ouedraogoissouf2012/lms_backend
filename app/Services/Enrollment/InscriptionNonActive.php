<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ClasseEtudiantStatut;
use App\Exceptions\BusinessException;
use App\Models\Classe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le refus de l'élève dont l'inscription n'est pas active (D57) : il n'a aucun accès,
 * et son refus dit ce motif, `enrolment_not_active`, partout où il est rendu (#1095).
 *
 * La règle : ce motif ne vaut que si une inscription ACTIVE l'aurait admis, c'est-à-dire
 * pour une ligne NON active dans une classe qui ouvre la ressource. Chaque lecteur
 * rejoue donc sa propre règle d'admission avec {@see classes()} à la place des classes
 * actives ; tout autre refus reste son 404, qui ne révèle pas la ressource.
 *
 * Le pivot est le même dans les deux types d'école : le prédicat sert les chapitres des
 * deux (`ChapterReadGate`) et les fiches de l'école du LMS.
 */
final class InscriptionNonActive
{
    public const REASON = 'enrolment_not_active';

    public const MESSAGE = 'Votre inscription n\'est pas active. Adressez-vous à votre établissement.';

    /**
     * Les classes où ce compte a une ligne NON active : le pendant de
     * `ClassesAtteintes::inscrites`, qui lit les lignes actives.
     *
     * @param  Builder<Classe>  $classes  déjà bornée EXPLICITEMENT à l'établissement
     * @return Builder<Classe>
     */
    public static function classes(Builder $classes, User $eleve): Builder
    {
        return $classes->whereHas('etudiants', static function ($inscrits) use ($eleve): void {
            $inscrits->where('users.id', $eleve->id)->where('classe_etudiant.statut', '!=', ClasseEtudiantStatut::Actif->value);
        });
    }

    /** @return array{status: int, message: string, reason: string} */
    public static function refus(): array
    {
        return ['status' => 409, 'message' => self::MESSAGE, 'reason' => self::REASON];
    }

    /**
     * Le même refus, dans la forme `{status, payload}` des lecteurs qui rendent leur
     * réponse (fiche d'une classe).
     *
     * @return array{status: int, payload: array{success: false, message: string, reason: string}}
     */
    public static function reponse(): array
    {
        return ['status' => 409, 'payload' => ['success' => false, 'message' => self::MESSAGE, 'reason' => self::REASON]];
    }

    public static function exception(): BusinessException
    {
        return new BusinessException(self::MESSAGE, 409, reason: self::REASON);
    }
}
