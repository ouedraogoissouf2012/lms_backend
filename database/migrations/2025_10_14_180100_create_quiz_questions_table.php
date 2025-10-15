<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Table quiz_questions
 *
 * Questions d'un quiz
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();

            // Relation
            $table->foreignId('quiz_id')->constrained()->onDelete('cascade');

            // Contenu de la question
            $table->text('question_text');
            $table->text('explanation')->nullable(); // Explication de la réponse correcte

            // Type de question
            $table->enum('type', [
                'multiple_choice',    // QCM (une seule réponse)
                'multiple_response',  // QCM (plusieurs réponses)
                'true_false',         // Vrai/Faux
                'short_answer',       // Réponse courte
                'essay',              // Rédaction longue
            ])->default('multiple_choice');

            // Configuration
            $table->integer('order')->default(0);
            $table->decimal('points', 6, 2)->default(1.00); // Points pour cette question
            $table->boolean('is_required')->default(true);

            // Métadonnées
            $table->json('metadata')->nullable(); // Données supplémentaires (images, etc.)

            $table->timestamps();

            // Index
            $table->index(['quiz_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};
