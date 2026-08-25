<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #541 — Quarantaine générique des lignes retirées par une migration
 * d'intégrité.
 *
 * Poser une clé étrangère ou un index unique sur une table historique suppose
 * d'en retirer d'abord les lignes qui les violent. Les supprimer sèchement dans
 * une migration est irréversible — l'audit 2026-08-15 a classé ce geste en P0.
 * On généralise donc le principe déjà appliqué par `content_corruption_backups`
 * (#231) : la ligne entière est copiée ici AVANT d'être retirée de sa table.
 *
 * `payload` porte la ligne intégrale sérialisée en JSON — colonne `text` et non
 * `json` : SQLite stocke le JSON en texte, et aucune requête ne filtre sur son
 * contenu (lecture humaine / restauration manuelle uniquement).
 *
 * L'unique `(source_table, source_row_id, reason)` rend la quarantaine idempotente :
 * une migration relancée après un échec partiel ne duplique pas les archives, et
 * une même ligne peut être archivée sous deux motifs distincts (deux FK).
 *
 * @see App\Services\Integrity\ArchivedRowWriter
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orphan_row_archive', function (Blueprint $table): void {
            $table->id();
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_row_id');
            $table->string('reason', 191)->comment('Motif du retrait : fk:<table>.<colonne> ou duplicate:<table>(<colonnes>)');
            $table->text('payload')->comment('Ligne d\'origine intégrale, sérialisée en JSON');
            $table->timestamp('archived_at')->useCurrent();

            $table->unique(['source_table', 'source_row_id', 'reason'], 'orphan_row_archive_source_unique');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orphan_row_archive');
    }
};
