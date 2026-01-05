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
        Schema::create('chapter_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('chapter_id')->constrained()->onDelete('cascade');
            $table->timestamp('completed_at')->nullable();
            $table->integer('time_spent_seconds')->default(0); // Temps passe sur le chapitre
            $table->integer('quiz_score')->nullable(); // Score si chapitre quiz
            $table->boolean('quiz_passed')->default(false); // Quiz reussi
            $table->timestamps();

            // Un utilisateur ne peut avoir qu'une seule progression par chapitre
            $table->unique(['user_id', 'chapter_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapter_progress');
    }
};
