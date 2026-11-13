<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * L'échéance d'une tentative, calculée UNE fois, au démarrage (C28) : la plus proche
 * de la fin de la fenêtre et de `started_at + duree_minutes`.
 *
 * La fenêtre est celle que la source de l'école vient de rendre au démarrage
 * (`EvaluationWindowGate`), dans les deux types d'école : la programmation KLASSCI,
 * ou la fenêtre calculée sur la ligne (`LocalEvaluationWindow`). Écrite sur la
 * copie (`ends_at`), l'échéance se compare ensuite à l'heure SANS rien demander à
 * KLASSCI : une panne au moment de la remise ne bloque aucune copie, et les deux
 * types d'école appliquent le même test ({@see \App\Models\Concerns\EcheanceDeLaCopie}).
 *
 * - Entraînement : aucune échéance (Q5).
 * - Fenêtre sans fin lisible (KLASSCI sans `end_at`) : la durée seule.
 */
final class EcheanceDeTentative
{
    /**
     * @param  array<string, mixed>|null  $fenetre
     */
    public static function pour(Evaluation $evaluation, ?array $fenetre, CarbonInterface $debut, bool $entrainement): ?CarbonInterface
    {
        if ($entrainement) {
            return null;
        }

        $parLaDuree = $debut->copy()->addMinutes((int) $evaluation->duree_minutes);
        $finDeFenetre = self::finDe($fenetre);

        return $finDeFenetre !== null && $finDeFenetre->lessThan($parLaDuree) ? $finDeFenetre : $parLaDuree;
    }

    /**
     * @param  array<string, mixed>|null  $fenetre
     */
    private static function finDe(?array $fenetre): ?CarbonInterface
    {
        $fin = $fenetre['end_at'] ?? null;
        if (! is_string($fin) || $fin === '') {
            return null;
        }

        try {
            return Carbon::parse($fin);
        } catch (InvalidFormatException) {
            // Une fin illisible ne doit pas empêcher de composer : la durée borne seule.
            return null;
        }
    }
}
