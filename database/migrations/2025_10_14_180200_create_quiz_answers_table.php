<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Table quiz_answers
 *
 * Réponses possibles pour les questions de type choix multiples
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();

            // Relation
            $table->foreignId('question_id')->constrained('quiz_questions')->onDelete('cascade');

            // Contenu de la réponse
            $table->text('answer_text');

            // Est-ce la bonne réponse?
            $table->boolean('is_correct')->default(false);

            // Ordre d'affichage
            $table->integer('order')->default(0);

            // Feedback spécifique à cette réponse (optionnel)
            $table->text('feedback')->nullable();

            $table->timestamps();

            // Index
            $table->index(['question_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_answers');
    }
};
