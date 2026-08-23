<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #582 — Index de balayage de la sync des séances.
 *
 * Le parcours des enseignants est désormais une pagination par clé (keyset) :
 * `WHERE role = ? AND institution_id ... ORDER BY institution_id, id`.
 *
 * Les index existants (`role` seul, `institution_id` seul) obligeraient MySQL à
 * trier la population à chaque passe. Le composite rend le parcours ordonné ET
 * positionné résoluble par index seul : à 200 000 utilisateurs (projection 10×
 * de PRODUCTION_STANDARDS.md §1.6), c'est la différence entre un `filesort`
 * complet toutes les 5 minutes et une recherche par intervalle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index(['role', 'institution_id', 'id'], 'users_sync_scan_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_sync_scan_index');
        });
    }
};
