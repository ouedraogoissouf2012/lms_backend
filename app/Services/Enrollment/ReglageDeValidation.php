<?php

declare(strict_types=1);

namespace App\Services\Enrollment;

use App\Enums\ClasseEtudiantStatut;
use App\Enums\EtatDeValidation;
use App\Models\Classe;
use App\Models\Institution;

/**
 * Le réglage d'école `inscriptions_a_valider` (#1658), lu à la NAISSANCE d'une adhésion par le geste de l'élève (code).
 *
 * - Vrai : l'adhésion naît `inactif` et `en_attente`. L'école la valide (accès ouvert, s'il reste une place) ou
 *   l'annule.
 * - Faux (par défaut) : elle naît `actif` et `validee`, comme avant.
 *
 * L'école est relue à chaque appel : aucun réglage n'est capturé.
 */
final class ReglageDeValidation
{
    public const EN_ATTENTE = 'enrolment_pending';

    public static function aValider(Classe $classe): bool
    {
        return (bool) Institution::query()->whereKey($classe->institution_id)->value('inscriptions_a_valider');
    }

    /** @return array{statut: string, validation: string} les colonnes d'une adhésion qui naît */
    public static function naissance(Classe $classe): array
    {
        return self::aValider($classe)
            ? ['statut' => ClasseEtudiantStatut::Inactif->value, 'validation' => EtatDeValidation::EnAttente->value]
            : ['statut' => ClasseEtudiantStatut::Actif->value, 'validation' => EtatDeValidation::Validee->value];
    }

    /** Une adhésion existante attend-elle la décision de l'école ? */
    public static function enAttente(Classe $classe, int $eleveId): bool
    {
        return $classe->etudiants()->newPivotStatementForId($eleveId)->value('validation') === EtatDeValidation::EnAttente->value;
    }
}
