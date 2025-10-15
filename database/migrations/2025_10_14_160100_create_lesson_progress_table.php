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
        Schema::create('lesson_progress', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('lesson_id')->constrained()->onDelete('cascade');

            // Progression
            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');
            $table->integer('progress_percentage')->default(0)->comment('Pourcentage de complétion (0-100)');
            $table->integer('time_spent_minutes')->default(0)->comment('Temps passé en minutes');

            // Dates importantes
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();

            // Notes et feedback
            $table->text('notes')->nullable()->comment('Notes personnelles de l\'étudiant');
            $table->integer('rating')->nullable()->comment('Note de 1 à 5');
            $table->text('feedback')->nullable()->comment('Feedback de l\'étudiant');

            $table->timestamps();

            // Contraintes uniques
            $table->unique(['user_id', 'lesson_id']);

            // Index
            $table->index('status');
            $table->index(['user_id', 'status']);
            $table->index(['lesson_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_progress');
    }
};
