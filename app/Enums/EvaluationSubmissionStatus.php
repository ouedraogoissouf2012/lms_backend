<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Concerns\EcheanceDeLaCopie;
use App\Models\EvaluationSubmission;

/**
 * Statut d'une {@see EvaluationSubmission} (#522, #705).
 *
 * `verrouille` (C28) : remise refusée après l'échéance. La copie garde ses
 * réponses, n'est pas notée et compte comme une tentative
 * ({@see EcheanceDeLaCopie}).
 */
enum EvaluationSubmissionStatus: string
{
    case EnCours = 'en_cours';
    case Soumis = 'soumis';
    case Corrige = 'corrige';
    case Verrouille = 'verrouille';
}
