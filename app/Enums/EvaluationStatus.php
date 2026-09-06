<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une {@see \App\Models\Evaluation} (#522, #705).
 */
enum EvaluationStatus: string
{
    case Brouillon = 'brouillon';
    case Planifiee = 'planifiee';
    case EnCours = 'en_cours';
    case Terminee = 'terminee';
}
