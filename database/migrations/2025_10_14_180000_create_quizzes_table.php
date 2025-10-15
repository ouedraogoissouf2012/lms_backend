<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Table quizzes
 *
 * Gestion des quiz/évaluations en ligne
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('lesson_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('matiere_id')->nullable()->constrained('matieres')->onDelete('set null');
            $table->foreignId('classe_id')->nullable()->constrained('classes')->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');

            // Informations du quiz
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();

            // Configuration
            $table->enum('type', ['formative', 'summative', 'diagnostic'])->default('formative');
            $table->integer('duration_minutes')->nullable(); // NULL = pas de limite
            $table->integer('max_attempts')->default(1); // Nombre de tentatives autorisées
            $table->decimal('passing_score', 5, 2)->default(50.00); // Score minimum pour réussir (%)

            // Options
            $table->boolean('shuffle_questions')->default(false); // Mélanger les questions
            $table->boolean('shuffle_answers')->default(false); // Mélanger les réponses
            $table->boolean('show_correct_answers')->default(true); // Montrer les bonnes réponses après
            $table->boolean('allow_review')->default(true); // Permettre la révision après soumission

            // Publication
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();

            // Statistiques
            $table->integer('total_questions')->default(0);
            $table->decimal('total_points', 8, 2)->default(0);
            $table->integer('attempts_count')->default(0);
            $table->decimal('average_score', 5, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('lesson_id');
            $table->index('matiere_id');
            $table->index('classe_id');
            $table->index('status');
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quizzes');
    }
};
