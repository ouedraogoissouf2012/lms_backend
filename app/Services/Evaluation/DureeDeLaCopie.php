<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use DateTimeInterface;

/**
 * La durée d'une copie, en minutes, la minute ENTAMÉE comptée (#901) : une copie
 * remise après 20 secondes a duré 1 minute, pas 0. Inconnue (`null`) quand le
 * début ou la remise manque — une note saisie hors ligne n'a pas de début — et
 * quand les dates se contredisent.
 *
 * Pure : aucune lecture, aucune horloge. `GET /my-grades` l'emploie
 * (`StudentGradesAggregator`), et le temps passé d'une évaluation (#967) peut
 * la réemployer.
 */
final class DureeDeLaCopie
{
    public static function enMinutes(?DateTimeInterface $debut, ?DateTimeInterface $remise): ?int
    {
        if ($debut === null || $remise === null) {
            return null;
        }

        $secondes = $remise->getTimestamp() - $debut->getTimestamp();
        if ($secondes < 0) {
            return null;
        }

        return intdiv($secondes + 59, 60);
    }
}
