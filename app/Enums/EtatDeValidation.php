<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * La décision de l'école sur une inscription (#1658), À CÔTÉ de son statut : le statut dit l'accès, la validation dit
 * la décision. Nulle sur une ligne où rien n'a été décidé dans le LMS (lignes anciennes, synchronisées de KLASSCI).
 */
enum EtatDeValidation: string
{
    case EnAttente = 'en_attente';
    case Validee = 'validee';
    case Annulee = 'annulee';
}
