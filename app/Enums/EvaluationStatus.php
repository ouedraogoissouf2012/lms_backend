<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Evaluation;

/**
 * Statut d'une {@see Evaluation} (#522, #705).
 */
enum EvaluationStatus: string
{
    case Brouillon = 'brouillon';
    case Planifiee = 'planifiee';
    case EnCours = 'en_cours';
    case Terminee = 'terminee';
}
