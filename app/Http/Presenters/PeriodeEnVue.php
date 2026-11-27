<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Program;
use App\Models\TrainingSession;

/**
 * La forme d'une Période vue par le client, partagée par la création, la liste et la modification
 * (#827, #1206, #1651).
 *
 * `phase` est DÉRIVÉE des dates : elle n'existe en base nulle part, et c'est ici qu'elle devient
 * visible du client (ADR-711-04). `en_cours` (#1651) est un booléen : la colonne vaut vrai ou NULL.
 */
final class PeriodeEnVue
{
    /** @return array<string, mixed> */
    public static function de(TrainingSession $periode): array
    {
        return [
            'id' => $periode->getKey(),
            'libelle' => $periode->libelle,
            'status' => $periode->status->value,
            'phase' => $periode->phase->value,
            'en_cours' => $periode->en_cours === true,
            'program_id' => $periode->program_id,
            // Chargé par le service, supprimé en douceur compris (#1206) : le titre se lit
            // ici, jamais en relisant `program_id`.
            'program' => $periode->program instanceof Program
                ? ['id' => $periode->program->getKey(), 'titre' => $periode->program->titre]
                : null,
            'enrollment_opens_at' => $periode->enrollment_opens_at?->toDateString(),
            'enrollment_closes_at' => $periode->enrollment_closes_at?->toDateString(),
            'starts_on' => $periode->starts_on?->toDateString(),
            'ends_on' => $periode->ends_on?->toDateString(),
            'certificate_available_at' => $periode->certificate_available_at?->toDateString(),
            'min_enrollments' => $periode->min_enrollments,
            'tarif' => $periode->tarif,
            'devise' => $periode->devise,
        ];
    }
}
