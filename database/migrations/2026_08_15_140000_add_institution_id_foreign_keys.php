<?php

declare(strict_types=1);

use App\Services\Tenancy\InstitutionForeignKeyGuard;
use App\Services\Tenancy\InstitutionIntegrityInspectorInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #583 — Clés étrangères manquantes sur `institution_id` (30 tables).
 *
 * La migration `2026_02_11_000002_add_institution_id_to_all_tables` a ajouté la
 * colonne + un index sur 30 tables, mais AUCUNE contrainte référentielle : rien
 * en base ne garantit l'intégrité du tenant. Cette migration ajoute les clés
 * étrangères `institution_id -> institutions(id)` en **ON DELETE RESTRICT**.
 *
 * ## Pourquoi RESTRICT (et pas CASCADE ni SET NULL)
 *
 *  - CASCADE : une suppression d'institution ne doit JAMAIS pouvoir vider 30
 *    tables en une requête.
 *  - SET NULL : transformerait les données d'un tenant supprimé en lignes
 *    orphelines silencieusement lisibles cross-tenant (fail-open).
 *  - RESTRICT : transforme la suppression d'une institution peuplée en erreur
 *    explicite — exactement le garde-fou anticipé par
 *    `PurgeSoftDeletedInstitutions` (#567), qui vérifie manuellement l'absence
 *    de lignes filles « en attendant les FK ». Cette migration fournit
 *    désormais cette garantie AU NIVEAU BASE.
 *
 * ## Colonne conservée nullable
 *
 * Une FK sur colonne nullable accepte `NULL` (comptes plateforme) et ne
 * contraint que les valeurs non nulles — comportement recherché. Aucun passage
 * en NOT NULL ici (changement cassant nécessitant un backfill, hors périmètre).
 *
 * ## Garde pré-vol — ne jamais migrer « à l'aveugle »
 *
 * Sous MySQL, `ALTER TABLE ADD FOREIGN KEY` déclenche un commit implicite (DDL
 * non transactionnel) : un échec en cours de boucle laisserait un schéma
 * partiellement contraint. On REFUSE donc de commencer si des lignes orphelines
 * existent, AVANT toute modification de schéma, en renvoyant vers la commande
 * d'audit. Le nettoyage (rattachement / archivage) est une décision humaine
 * informée par la mesure — cf. `.claude/specs/583-fk-institution-id/`.
 *
 * @see config/tenancy.php
 * @see App\Services\Tenancy\InstitutionIntegrityInspector
 * @see App\Console\Commands\AuditInstitutionOrphans
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = $this->tables();

        // Garde pré-vol : abort AVANT tout DDL si des orphelins subsistent.
        app(InstitutionForeignKeyGuard::class)->ensureNoOrphans($tables);

        $inspector = $this->inspector();

        foreach ($inspector->scopedTablesPresent($tables) as $table) {
            if ($inspector->hasInstitutionForeignKey($table)) {
                continue; // Idempotence : relance sûre après échec partiel éventuel.
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign('institution_id')
                    ->references('id')->on('institutions')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $inspector = $this->inspector();

        foreach ($inspector->scopedTablesPresent($this->tables()) as $table) {
            if (! $inspector->hasInstitutionForeignKey($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['institution_id']);
            });
        }
    }

    /**
     * Résolution via le conteneur : usage légitime dans une migration (couche
     * infrastructure, au même titre que la facade `Schema`) — hors couche métier
     * soumise au §1.6-D.
     */
    private function inspector(): InstitutionIntegrityInspectorInterface
    {
        return app(InstitutionIntegrityInspectorInterface::class);
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        /** @var list<string> $tables */
        $tables = config('tenancy.institution_scoped_tables', []);

        return $tables;
    }
};
