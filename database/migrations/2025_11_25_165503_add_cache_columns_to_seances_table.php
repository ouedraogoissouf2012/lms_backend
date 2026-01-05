<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seances', function (Blueprint $table) {
            // Cache des données KLASSCI pour éviter les appels API répétés
            // Note: matiere_nom et enseignant_nom existent déjà (migration 2025_10_20_215328)
            $table->string('classe_nom')->nullable()->after('klassci_classe_id');
            $table->string('titre')->nullable()->after('enseignant_nom');
            $table->timestamp('date_seance')->nullable()->after('titre');

            // Index pour recherche rapide
            $table->index('date_seance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table) {
            $table->dropIndex(['date_seance']);
            $table->dropColumn(['classe_nom', 'titre', 'date_seance']);
        });
    }
};
