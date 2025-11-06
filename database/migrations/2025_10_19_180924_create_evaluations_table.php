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
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();

            // Liaison avec KLASSCI
            $table->unsignedBigInteger('klassci_evaluation_id')->nullable()->comment('ID de l\'évaluation dans KLASSCI');
            $table->unsignedBigInteger('klassci_matiere_id')->comment('ID de la matière dans KLASSCI');
            $table->unsignedBigInteger('klassci_classe_id')->comment('ID de la classe dans KLASSCI');
            $table->unsignedBigInteger('klassci_enseignant_id')->nullable()->comment('ID de l\'enseignant dans KLASSCI');

            // Informations de base
            $table->string('titre');
            $table->text('description')->nullable();
            $table->string('type', 50)->default('qcm');
            $table->string('status', 50)->default('brouillon');

            // Programmation
            $table->dateTime('date_evaluation')->nullable();
            $table->integer('duree_minutes')->default(60);
            $table->decimal('coefficient', 5, 2)->default(1.00);
            $table->decimal('bareme', 5, 2)->default(20.00);

            // Configuration LMS
            $table->boolean('is_online')->default(true)->comment('Évaluation en ligne sur le LMS');
            $table->boolean('allow_retake')->default(false)->comment('Autoriser les reprises');
            $table->integer('max_attempts')->default(1)->comment('Nombre de tentatives autorisées');
            $table->boolean('shuffle_questions')->default(false)->comment('Mélanger les questions');
            $table->boolean('show_results')->default(false)->comment('Afficher les résultats immédiatement');

            // Publication
            $table->boolean('is_published')->default(false)->comment('Visible par les étudiants');
            $table->boolean('notes_published')->default(false)->comment('Notes publiées dans KLASSCI');

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('klassci_evaluation_id');
            $table->index('klassci_classe_id');
            $table->index('klassci_matiere_id');
            $table->index('status');
            $table->index('date_evaluation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
