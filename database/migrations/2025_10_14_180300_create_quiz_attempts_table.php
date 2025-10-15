<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Table quiz_attempts
 *
 * Tentatives de quiz par les étudiants
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('quiz_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Informations de la tentative
            $table->integer('attempt_number')->default(1); // 1ère, 2ème tentative, etc.
            $table->enum('status', ['in_progress', 'submitted', 'graded', 'abandoned'])->default('in_progress');

            // Timing
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->integer('time_spent_seconds')->nullable(); // Temps réellement passé

            // Scoring
            $table->decimal('score', 5, 2)->nullable(); // Score en pourcentage (0-100)
            $table->decimal('points_earned', 8, 2)->nullable(); // Points obtenus
            $table->decimal('points_possible', 8, 2)->nullable(); // Points possibles
            $table->boolean('passed')->nullable(); // A réussi le quiz?

            // Données des réponses (JSON)
            $table->json('answers')->nullable(); // Structure: {question_id: answer_data}

            // Feedback enseignant (pour essays, etc.)
            $table->text('teacher_feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            // Index
            $table->index(['quiz_id', 'user_id', 'attempt_number']);
            $table->index('status');
            $table->unique(['quiz_id', 'user_id', 'attempt_number']); // Une seule tentative N par utilisateur
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
    }
};
