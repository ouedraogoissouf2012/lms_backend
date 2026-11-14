<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une {@see \App\Models\EvaluationSubmission} (#522, #705).
 *
 * `verrouille` (C28) : remise refusée après l'échéance. La copie garde ses
 * réponses, n'est pas notée et compte comme une tentative
 * ({@see \App\Models\Concerns\EcheanceDeLaCopie}).
 */
enum EvaluationSubmissionStatus: string
{
    case EnCours = 'en_cours';
    case Soumis = 'soumis';
    case Corrige = 'corrige';
    case Verrouille = 'verrouille';
}
