<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ajoute les foreign keys manquantes à la table lessons
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // Supprimer les anciennes colonnes qui ne sont pas des foreign keys
            $table->dropColumn(['matiere_id', 'classe_id', 'enseignant_id']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            // Ajouter les vraies foreign keys après id
            $table->foreignId('matiere_id')->after('id')->nullable()->constrained('matieres')->onDelete('set null');
            $table->foreignId('classe_id')->after('matiere_id')->nullable()->constrained('classes')->onDelete('set null');
            $table->foreignId('enseignant_id')->after('classe_id')->constrained('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropForeign(['matiere_id']);
            $table->dropForeign(['classe_id']);
            $table->dropForeign(['enseignant_id']);

            $table->dropColumn(['matiere_id', 'classe_id', 'enseignant_id']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            // Remettre les colonnes simples
            $table->unsignedBigInteger('matiere_id')->nullable()->after('id');
            $table->unsignedBigInteger('classe_id')->nullable()->after('matiere_id');
            $table->unsignedBigInteger('enseignant_id')->nullable()->after('classe_id');
        });
    }
};
