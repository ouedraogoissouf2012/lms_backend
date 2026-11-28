<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1702 (D1) — la banque de questions : des catégories par enseignant et par matière, et leurs
 * questions (les champs d'une question d'évaluation). Elle ne touche aucune évaluation : le
 * tirage (D3) y puisera, en COPIANT les questions dans l'évaluation à sa publication.
 *
 * Tables neuves : aucune donnée existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories_de_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('enseignant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('matiere_id')->constrained('matieres')->restrictOnDelete();
            $table->string('libelle', 150);
            $table->timestamps();

            $table->unique(['enseignant_id', 'matiere_id', 'libelle'], 'categories_de_questions_libelle_unique');
            $table->index('institution_id');
        });

        Schema::create('questions_de_banque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('categorie_id')->constrained('categories_de_questions')->cascadeOnDelete();
            $table->text('question');
            $table->string('type', 20);
            $table->decimal('points', 5, 2)->default(1);
            $table->json('options')->nullable();
            $table->json('correct_answers')->nullable();
            $table->text('explanation')->nullable();
            $table->timestamps();

            $table->index('institution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions_de_banque');
        Schema::dropIfExists('categories_de_questions');
    }
};
