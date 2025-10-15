<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tables de synchronisation pour les entités KLASSCI principales
     */
    public function up(): void
    {
        // Table Matières (synchronisée depuis KLASSCI)
        Schema::create('matieres', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('klassci_id')->unique()->comment('ID matière KLASSCI');

            // Données de base
            $table->string('code')->nullable();
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->integer('coefficient')->default(1);
            $table->integer('credit')->default(1);

            // Relations KLASSCI
            $table->unsignedBigInteger('filiere_id')->nullable()->comment('ID filière KLASSCI');
            $table->unsignedBigInteger('niveau_id')->nullable()->comment('ID niveau KLASSCI');
            $table->unsignedBigInteger('semestre_id')->nullable()->comment('ID semestre KLASSCI');

            // Métadonnées
            $table->json('klassci_data')->nullable()->comment('Données complètes KLASSCI');
            $table->timestamp('last_klassci_sync')->nullable();

            $table->timestamps();

            // Index
            $table->index('klassci_id');
            $table->index('filiere_id');
            $table->index('niveau_id');
        });

        // Table Classes (synchronisée depuis KLASSCI)
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('klassci_id')->unique()->comment('ID classe KLASSCI');

            // Données de base
            $table->string('code')->nullable();
            $table->string('libelle');
            $table->text('description')->nullable();
            $table->integer('effectif')->default(0);

            // Relations KLASSCI
            $table->unsignedBigInteger('filiere_id')->nullable()->comment('ID filière KLASSCI');
            $table->unsignedBigInteger('niveau_id')->nullable()->comment('ID niveau KLASSCI');
            $table->unsignedBigInteger('annee_universitaire_id')->nullable()->comment('ID année KLASSCI');

            // Métadonnées
            $table->json('klassci_data')->nullable()->comment('Données complètes KLASSCI');
            $table->timestamp('last_klassci_sync')->nullable();

            $table->timestamps();

            // Index
            $table->index('klassci_id');
            $table->index('filiere_id');
            $table->index('niveau_id');
            $table->index('annee_universitaire_id');
        });

        // Table pivot Matières-Classes (relation N-N)
        Schema::create('classe_matiere', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classe_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('matiere_id')->constrained('matieres')->onDelete('cascade');
            $table->foreignId('enseignant_id')->nullable()->constrained('users')->onDelete('set null');

            // Métadonnées de la relation
            $table->integer('coefficient')->nullable();
            $table->integer('heures_cours')->nullable();
            $table->integer('heures_td')->nullable();
            $table->integer('heures_tp')->nullable();

            $table->timestamps();

            // Contrainte unique
            $table->unique(['classe_id', 'matiere_id']);

            // Index
            $table->index('enseignant_id');
        });

        // Table Étudiants-Classes (inscription)
        Schema::create('classe_etudiant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classe_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Métadonnées de l'inscription
            $table->date('date_inscription')->nullable();
            $table->enum('statut', ['actif', 'inactif', 'abandonne'])->default('actif');
            $table->unsignedBigInteger('annee_universitaire_id')->nullable();

            $table->timestamps();

            // Contrainte unique (un étudiant par classe par année)
            $table->unique(['classe_id', 'user_id', 'annee_universitaire_id'], 'unique_classe_user_annee');

            // Index
            $table->index(['classe_id', 'statut']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classe_etudiant');
        Schema::dropIfExists('classe_matiere');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('matieres');
    }
};
