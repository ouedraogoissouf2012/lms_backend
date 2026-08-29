<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #582 — Marquage de confirmation KLASSCI d'une séance.
 *
 * L'archivage des séances disparues de KLASSCI reposait sur une accumulation
 * EN MÉMOIRE des identifiants actifs, valable seulement si une passe de sync
 * couvrait toute la population d'un coup. Avec la reprise par curseur, un cycle
 * s'étale sur plusieurs passes : l'accumulation ne survit pas d'une passe à
 * l'autre. On la remplace par un marquage durable porté par la ligne.
 *
 * `synced_at` = dernière fois où KLASSCI a confirmé l'existence de cette séance.
 * À la complétude d'un tenant, tout ce qui n'a pas été confirmé depuis le début
 * du cycle courant est archivé (« mark & sweep »).
 *
 * `NULL` sur les lignes historiques est le comportement voulu : une séance encore
 * présente côté KLASSCI sera marquée avant la clôture de son tenant ; celles qui
 * restent à NULL sont précisément celles à archiver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->timestamp('synced_at')->nullable()->after('date_seance')
                ->comment('Dernière confirmation de la séance par KLASSCI (#582)');

            // Index de balayage : la requête d'archivage est exactement
            // institution_id = ? AND is_active = 1 AND (synced_at IS NULL OR synced_at < ?).
            $table->index(['institution_id', 'is_active', 'synced_at'], 'seances_tenant_sweep_index');
        });
    }

    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropIndex('seances_tenant_sweep_index');
            $table->dropColumn('synced_at');
        });
    }
};
