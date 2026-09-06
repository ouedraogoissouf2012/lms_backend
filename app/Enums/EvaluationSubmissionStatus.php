<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une {@see \App\Models\EvaluationSubmission} (#522, #705).
 */
enum EvaluationSubmissionStatus: string
{
    case EnCours = 'en_cours';
    case Soumis = 'soumis';
    case Corrige = 'corrige';
}
