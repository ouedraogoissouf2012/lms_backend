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
        Schema::create('knowledge_check_attempts', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->foreignId('knowledge_check_id')->constrained('knowledge_checks')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Resultats
            $table->integer('score')->comment('Score obtenu en pourcentage');
            $table->integer('correct_answers')->default(0)->comment('Nombre de bonnes reponses');
            $table->integer('total_questions')->default(0)->comment('Nombre total de questions');

            // Reponses de l'utilisateur au format JSON
            // Structure: [{question_index, user_answer, is_correct, time_spent_seconds}]
            $table->json('answers')->nullable();

            // Temps passe
            $table->integer('time_spent_seconds')->default(0);

            // Statut
            $table->boolean('passed')->default(false)->comment('A reussi le quiz');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Index
            $table->index('knowledge_check_id');
            $table->index('user_id');
            $table->index('passed');
            $table->index(['knowledge_check_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_check_attempts');
    }
};
