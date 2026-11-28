<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1677 (B1) — le modèle de bulletin d'une école, et les appréciations qui s'y écriront.
 *
 * - `modeles_de_bulletin` : une configuration par école (en-tête, signataires, barème des
 *   appréciations, blocs de matières, mentions affichées).
 * - `appreciations_de_bulletin` : pour un élève d'une classe et une PÉRIODE (un intervalle de
 *   dates nommé : `periode_libelle`, `periode_du`, `periode_au`), l'appréciation d'une matière par
 *   son enseignant, ou l'appréciation générale et la décision du conseil par la gestion
 *   (`matiere_id` nul). Une sous-période (S1, S2) se branchera sur le même intervalle.
 *
 * Tables neuves : aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modeles_de_bulletin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->unique()->constrained('institutions')->restrictOnDelete();
            $table->json('entete')->nullable();
            $table->json('signataires')->nullable();
            $table->json('bareme')->nullable();
            $table->json('blocs')->nullable();
            $table->json('mentions')->nullable();
            $table->timestamps();
        });

        Schema::create('appreciations_de_bulletin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('matiere_id')->nullable()->constrained('matieres')->cascadeOnDelete();
            $table->string('periode_libelle', 100);
            $table->date('periode_du');
            $table->date('periode_au');
            $table->text('texte')->nullable();
            $table->string('decision', 255)->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['classe_id', 'periode_du', 'periode_au'], 'appreciations_classe_periode_index');
            $table->index('institution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appreciations_de_bulletin');
        Schema::dropIfExists('modeles_de_bulletin');
    }
};
