<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1669 — la grille hebdomadaire d'une classe, sans salle (ARRÊT 1 validé le 28/11).
 *
 * - `emplois_du_temps` : une table par école (déclarée dans `tenancy.institution_scoped_tables`, clé de l'école en
 *   RESTRICT). `du` et `au` restent dans la période de la classe.
 * - `lignes_emploi_du_temps` : la semaine type. Une ligne de COURS porte une matière et son enseignant ; une PAUSE n'a
 *   ni matière ni enseignant, mais un libellé. Cascade avec son emploi du temps.
 * - `seances.emploi_du_temps_ligne_id` : la ligne qui a généré la séance. L'unicité (ligne, date) rend la génération
 *   idempotente par construction. Une ligne supprimée laisse ses séances tenues, sans lien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emplois_du_temps', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
            $table->string('titre', 150);
            $table->date('du');
            $table->date('au');
            $table->boolean('en_vigueur')->default(true);
            $table->timestamps();

            $table->index(['institution_id', 'classe_id']);
        });

        Schema::create('lignes_emploi_du_temps', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('emploi_du_temps_id')->constrained('emplois_du_temps')->cascadeOnDelete();
            $table->unsignedTinyInteger('jour');
            $table->time('debut');
            $table->time('fin');
            $table->foreignId('matiere_id')->nullable()->constrained('matieres')->nullOnDelete();
            $table->foreignId('enseignant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type_seance', 32)->nullable();
            $table->string('libelle', 100)->nullable();
            $table->timestamps();

            $table->index(['emploi_du_temps_id', 'jour']);
        });

        Schema::table('seances', static function (Blueprint $table): void {
            $table->foreignId('emploi_du_temps_ligne_id')->nullable()->after('classe_id')
                ->constrained('lignes_emploi_du_temps')->nullOnDelete();
            $table->unique(['emploi_du_temps_ligne_id', 'date_seance']);
        });
    }

    public function down(): void
    {
        Schema::table('seances', static function (Blueprint $table): void {
            $table->dropUnique(['emploi_du_temps_ligne_id', 'date_seance']);
            $table->dropConstrainedForeignId('emploi_du_temps_ligne_id');
        });
        Schema::dropIfExists('lignes_emploi_du_temps');
        Schema::dropIfExists('emplois_du_temps');
    }
};
