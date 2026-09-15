<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cycle de vie d'une demande d'ouverture d'école (#803).
 *
 * Trois états, et deux transitions seulement, toutes deux décidées par le
 * supradmin plateforme :
 *
 *     EnAttente ──valider──▶ Validee
 *               └─refuser──▶ Refusee
 *
 * Aucun retour en arrière : une demande tranchée le reste. Rouvrir un dossier
 * se fait en déposant une nouvelle demande, ce que l'unicité partielle autorise
 * (elle ne borne que les demandes `en_attente`).
 *
 * @see docs/adr/2026-09-15-803-01-demande-publique.md
 */
enum SchoolRequestStatus: string
{
    case EnAttente = 'en_attente';
    case Validee = 'validee';
    case Refusee = 'refusee';
}
