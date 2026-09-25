<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * État du code d'inscription d'une classe — DÉRIVÉ de ses deux colonnes,
 * jamais stocké (#905, ADR-905-01).
 *
 * Même forme que {@see TrainingSessionPhase} : un état qu'aucune colonne ne
 * porte reste un état de domaine, et CONTRIBUTING (#522) le veut en énumération
 * plutôt qu'en chaîne répétée.
 *
 * ## Un code retiré ne se montre plus
 *
 * Sa valeur reste en base : elle garde l'unicité d'un code tout juste dicté
 * (voir la migration du code d'inscription). Mais l'afficher inviterait à
 * dicter un code qui n'ouvre rien. {@see self::valeurVisible()} tient cette
 * règle en un seul endroit.
 */
enum EtatCodeInscription: string
{
    case Actif = 'actif';
    case Retire = 'retire';
    case Absent = 'absent';

    public static function depuis(?string $code, ?CarbonInterface $revoqueLe): self
    {
        if ($code === null || $code === '') {
            return self::Absent;
        }

        return $revoqueLe === null ? self::Actif : self::Retire;
    }

    /**
     * Le code tel qu'il peut être montré à qui gère la classe : lui-même s'il
     * ouvre encore, rien sinon.
     */
    public function valeurVisible(?string $code): ?string
    {
        return $this === self::Actif ? $code : null;
    }
}
