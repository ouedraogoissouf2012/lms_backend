<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1666 — le profil et les disponibilités d'un enseignant (ARRÊT 1 validé le 28/11).
 *
 * Trois tables par école (déclarées dans `tenancy.institution_scoped_tables`, clé de l'école en RESTRICT), toutes
 * rattachées à l'id LOCAL du compte (`users.id`), jamais à un id KLASSCI, dans les deux volets.
 * - `profils_enseignant` : une ligne au plus par compte. Le matricule est FACULTATIF et unique par école : l'index
 *   unique (école, matricule) laisse passer plusieurs NULL (MySQL et SQLite).
 * - `disponibilites_enseignant` : la semaine type, jour ISO 1-7, heure de début et de fin, disponible ou non.
 * - `exceptions_disponibilite` : une date, un créneau facultatif (sans créneau : la journée entière), un motif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profils_enseignant', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('matricule', 50)->nullable();
            $table->string('specialite', 150)->nullable();
            $table->string('titre', 50)->nullable();
            $table->string('grade', 32)->nullable();
            $table->string('diplome', 150)->nullable();
            $table->string('universite', 150)->nullable();
            $table->date('date_debut')->nullable();
            $table->string('statut', 16)->nullable();
            $table->unsignedTinyInteger('charge_hebdo_max')->nullable();
            $table->timestamps();

            $table->unique(['institution_id', 'matricule']);
        });

        Schema::create('disponibilites_enseignant', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('jour');
            $table->time('debut');
            $table->time('fin');
            $table->boolean('disponible');
            $table->timestamps();

            $table->index(['user_id', 'jour']);
        });

        Schema::create('exceptions_disponibilite', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->time('debut')->nullable();
            $table->time('fin')->nullable();
            $table->boolean('disponible');
            $table->string('motif', 150)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exceptions_disponibilite');
        Schema::dropIfExists('disponibilites_enseignant');
        Schema::dropIfExists('profils_enseignant');
    }
};
