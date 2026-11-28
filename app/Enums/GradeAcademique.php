<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le grade académique d'un enseignant (#1666), une énumération FERMÉE, de l'assistant au professeur (KLASSCI,
 * `/esbtp/enseignants`).
 */
enum GradeAcademique: string
{
    case Assistant = 'assistant';
    case MaitreAssistant = 'maitre_assistant';
    case MaitreDeConferences = 'maitre_de_conferences';
    case Professeur = 'professeur';
}
