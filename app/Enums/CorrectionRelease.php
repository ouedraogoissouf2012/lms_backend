<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Evaluation\Student\OuvertureDuCorrige;

/**
 * Quand le corrigé d'une évaluation s'ouvre à l'élève (`evaluations.correction_release`, #1699).
 * C'est l'enseignant qui le choisit.
 *
 * Quel que soit le mode, jamais avant la fermeture de l'évaluation pour tous : c'est le
 * plancher commun, appliqué par {@see OuvertureDuCorrige}.
 */
enum CorrectionRelease: string
{
    /** Jamais communiqué. */
    case Never = 'never';

    /** À la publication des notes (`show_results`). C'est le défaut. */
    case WithResults = 'with_results';

    /** À la fermeture, notes publiées ou non. */
    case AfterClose = 'after_close';

    /** À la date choisie (`correction_release_at`). */
    case AtDate = 'at_date';
}
