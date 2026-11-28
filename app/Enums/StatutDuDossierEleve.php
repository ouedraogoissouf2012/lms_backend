<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\DossierEleve;

/**
 * Le statut du DOSSIER d'un élève d'une école autonome (#1657), {@see DossierEleve}. Il dit où en est l'élève dans
 * l'école, pas son inscription dans une classe (`ClasseEtudiantStatut`, qui a son propre `actif`).
 */
enum StatutDuDossierEleve: string
{
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Diplome = 'diplome';
    case Parti = 'parti';
}
