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
        Schema::create('knowledge_checks', function (Blueprint $table) {
            $table->id();

            // Relation avec le chapitre
            $table->foreignId('chapter_id')->constrained('chapters')->onDelete('cascade');

            // Informations du quiz
            $table->string('title');
            $table->text('description')->nullable();

            // Questions au format JSON
            // Structure: [{question, type, options, correct_answer, explanation, points}]
            $table->json('questions');

            // Configuration du quiz
            $table->integer('passing_score')->default(70)->comment('Score minimum pour reussir (%)');
            $table->integer('max_attempts')->nullable()->comment('Nombre max de tentatives (null = illimite)');
            $table->boolean('shuffle_questions')->default(false)->comment('Melanger les questions');
            $table->boolean('shuffle_options')->default(false)->comment('Melanger les options de reponse');
            $table->boolean('show_correct_answers')->default(true)->comment('Afficher les reponses correctes apres');
            $table->boolean('show_explanation')->default(true)->comment('Afficher les explications');
            $table->integer('time_limit_minutes')->nullable()->comment('Limite de temps (null = pas de limite)');

            // Position dans le chapitre
            $table->integer('position')->default(0)->comment('Position apres quel element du chapitre');

            // Statut
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('chapter_id');
            $table->index('position');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_checks');
    }
};
