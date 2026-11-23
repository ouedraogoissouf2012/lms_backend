<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use RuntimeException;

/**
 * Annule le passage de `institution_id` en NOT NULL tant qu'une ligne n'a pas d'école (#801).
 *
 * ## Elle ne supprime RIEN
 *
 * Une quarantaine automatique (`RowQuarantine::purge`) supprime par `id`. Sur une table
 * parente, elle détruirait EN CASCADE des enfants qui ont pourtant une école, sans archive :
 * `chapters.lesson_id`, `forum_topics.lesson_id`, `quizzes.lesson_id` sont en CASCADE (graphe
 * mesuré pour #719). L'exploitant tranche ; la garde ne fait que refuser, en le disant.
 *
 * ## Son message se lit dans le journal de DÉPLOIEMENT
 *
 * Fusion = déploiement : si elle annule en production, c'est le déploiement qui échoue. Le
 * message nomme donc les tables, leur compte, et la commande d'audit.
 *
 * Le comptage est celui de l'inspecteur existant ({@see InstitutionIntegrityInspectorInterface}).
 */
final class GardeDesInstitutionsNulles
{
    public function __construct(private readonly InstitutionIntegrityInspectorInterface $inspecteur) {}

    /**
     * @param  list<string>  $tables
     *
     * @throws RuntimeException si une table présente porte encore une ligne sans école
     */
    public function exigerAucunNull(array $tables): void
    {
        $nulles = [];
        foreach ($this->inspecteur->scopedTablesPresent($tables) as $table) {
            $compte = $this->inspecteur->nullCount($table);
            if ($compte > 0) {
                $nulles[] = "  - {$table} : {$compte} ligne(s)";
            }
        }

        if ($nulles !== []) {
            throw new RuntimeException(
                "Migration #801 annulée AVANT toute modification du schéma : des lignes sans\n"
                ."établissement (institution_id NULL) subsistent.\n"
                .implode("\n", $nulles)."\n"
                ."Aucune ligne n'a été supprimée. Lancez `php artisan institutions:audit-orphans`\n"
                .'pour le détail, rattachez ou archivez ces lignes, puis relancez le déploiement.',
            );
        }
    }
}
