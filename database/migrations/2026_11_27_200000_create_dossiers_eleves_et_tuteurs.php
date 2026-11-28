<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1657 : le dossier de l'élève d'une école autonome (identité, matricule) et ses tuteurs.
 *
 * - `dossiers_eleves` : un par compte élève. Il part avec le compte purgé (cascade). Le matricule est unique dans
 *   l'école.
 * - `tuteurs` : dans l'école. Ils se partagent entre frères et sœurs par `dossier_tuteur`, qui porte la relation.
 * - `matricules_sequences` : la séquence du matricule, par école et par année, incrémentée sous verrou.
 * - `institutions.matricule_gabarit` : le gabarit de l'école (`{AAAA}-{NNNN}` quand il est nul).
 *
 * Seuls les champs utiles (loi 2013-450) : aucun champ libre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', static function (Blueprint $table): void {
            $table->string('matricule_gabarit', 40)->nullable();
        });

        Schema::create('matricules_sequences', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->unsignedSmallInteger('annee');
            $table->unsignedInteger('dernier')->default(0);
            $table->timestamps();
            $table->unique(['institution_id', 'annee']);
        });

        Schema::create('dossiers_eleves', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('matricule', 40);
            $table->string('nom', 100);
            $table->string('prenoms', 150);
            $table->char('genre', 1)->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('lieu_naissance', 100)->nullable();
            $table->char('nationalite', 2)->nullable();
            $table->string('adresse', 255)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('commune', 100)->nullable();
            $table->string('statut', 16)->default('actif');
            $table->timestamps();
            $table->unique(['institution_id', 'matricule']);
            $table->index(['institution_id', 'date_naissance']);
        });

        Schema::create('tuteurs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('nom', 150);
            $table->string('telephone', 30)->nullable();
            $table->string('courriel', 255)->nullable();
            $table->string('profession', 100)->nullable();
            $table->string('adresse', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('dossier_tuteur', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dossier_eleve_id')->constrained('dossiers_eleves')->cascadeOnDelete();
            $table->foreignId('tuteur_id')->constrained('tuteurs')->cascadeOnDelete();
            $table->string('relation', 16);
            $table->timestamps();
            $table->unique(['dossier_eleve_id', 'tuteur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_tuteur');
        Schema::dropIfExists('tuteurs');
        Schema::dropIfExists('dossiers_eleves');
        Schema::dropIfExists('matricules_sequences');
        Schema::table('institutions', static function (Blueprint $table): void {
            $table->dropColumn('matricule_gabarit');
        });
    }
};
