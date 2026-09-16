<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'une Période — la décision HUMAINE (#800, ADR-711-04).
 *
 * ## Ce qui se stocke, et ce qui se dérive
 *
 * Ce statut est stocké parce qu'il traduit un choix : publier, annuler, clore.
 * La **phase** — à venir, inscriptions ouvertes, en cours, terminée — n'est pas
 * ici : elle se déduit des cinq dates, et n'a donc aucune colonne. Mélanger les
 * deux imposerait un job nocturne pour faire avancer l'étiquette, qui finirait
 * par diverger des dates en silence.
 *
 * Canvas ne stocke que des décisions humaines ; Masteriyo dérive les phases des
 * dates. Le mélange est le défaut que cette séparation évite.
 *
 * ## « Reportée » n'est pas un état
 *
 * Reporter une Période, c'est rebaser son calendrier — une opération, pas un
 * statut. L'ajouter ici créerait un état dont personne ne saurait sortir.
 *
 * @see docs/adr/2026-09-06-711-04-status-phase.md
 */
enum TrainingSessionStatus: string
{
    case Brouillon = 'brouillon';
    case Publiee = 'publiee';
    case Annulee = 'annulee';
    case Cloturee = 'cloturee';
    case Archivee = 'archivee';
    case Purgee = 'purgee';
}
