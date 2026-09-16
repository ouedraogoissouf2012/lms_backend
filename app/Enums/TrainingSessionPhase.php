<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * Phase d'une Période — DÉRIVÉE des dates, jamais stockée (#800, ADR-711-04).
 *
 * Aucune colonne ne la porte, et c'est délibéré : une colonne exigerait un job
 * nocturne pour la faire avancer, qui finirait par diverger des dates en
 * silence. Le statut — la décision humaine — est stocké ailleurs
 * ({@see TrainingSessionStatus}) ; la phase n'est qu'une lecture.
 *
 * Elle ignore volontairement le statut : une Période annulée garde la phase que
 * ses dates décrivent. Croiser les deux ici rendrait la dérivation dépendante
 * d'une décision humaine, et l'on retomberait dans le mélange que l'ADR écarte.
 */
enum TrainingSessionPhase: string
{
    case AVenir = 'a_venir';
    case InscriptionsOuvertes = 'inscriptions_ouvertes';
    case EnCours = 'en_cours';
    case Terminee = 'terminee';

    /**
     * Les dates absentes ne font pas basculer de phase : une Période sans date
     * de fin n'est pas « terminée », elle reste en cours.
     */
    public static function depuisLesDates(
        ?Carbon $ouvertureInscriptions,
        ?Carbon $fermetureInscriptions,
        ?Carbon $debut,
        ?Carbon $fin,
    ): self {
        $aujourdHui = Carbon::today();

        if ($fin !== null && $aujourdHui->greaterThan($fin)) {
            return self::Terminee;
        }

        if ($debut !== null && $aujourdHui->greaterThanOrEqualTo($debut)) {
            return self::EnCours;
        }

        $inscriptionsOuvertes = $ouvertureInscriptions !== null
            && $aujourdHui->greaterThanOrEqualTo($ouvertureInscriptions)
            && ($fermetureInscriptions === null || $aujourdHui->lessThanOrEqualTo($fermetureInscriptions));

        return $inscriptionsOuvertes ? self::InscriptionsOuvertes : self::AVenir;
    }
}
