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
        Schema::create('matiere_enseignant', function (Blueprint $table) {
            $table->id();

            // ID de la matière dans KlassCI
            $table->unsignedBigInteger('klassci_matiere_id');

            // ID de l'enseignant dans KlassCI
            $table->unsignedBigInteger('klassci_enseignant_id');

            // Année universitaire (optionnel, pour historique)
            $table->unsignedBigInteger('annee_universitaire_id')->nullable();

            // Statut de l'assignation
            $table->enum('status', ['active', 'inactive'])->default('active');

            // Qui a créé cette assignation
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');

            $table->timestamps();

            // Index pour recherche rapide
            $table->index(['klassci_matiere_id', 'status']);
            $table->index(['klassci_enseignant_id', 'status']);

            // Éviter les doublons (une même assignation)
            $table->unique(['klassci_matiere_id', 'klassci_enseignant_id', 'annee_universitaire_id'], 'unique_matiere_enseignant_annee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matiere_enseignant');
    }
};
