<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1702 (D3) — le tirage au sort : une évaluation tire N questions d'une catégorie de la banque.
 *
 * - `tirages_d_evaluation` : une règle (évaluation, catégorie, nombre). La catégorie peut
 *   disparaître de la banque après la publication : la règle garde son nombre (`categorie_id`
 *   devient nul), le vivier copié reste.
 * - `evaluation_questions.tirage_id` : la question appartient au VIVIER d'une règle, copié de la
 *   banque à la publication (`question_de_banque_id` dit d'où). Une question sans règle est posée
 *   à tous ; une question d'un vivier ne l'est qu'à ceux que le tirage désigne.
 *
 * Tables et colonnes neuves, nulles par défaut : aucune évaluation existante ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tirages_d_evaluation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('evaluation_id')->constrained('evaluations')->cascadeOnDelete();
            $table->foreignId('categorie_id')->nullable()->constrained('categories_de_questions')->nullOnDelete();
            $table->unsignedSmallInteger('nombre');
            $table->timestamps();

            $table->unique(['evaluation_id', 'categorie_id'], 'tirages_evaluation_categorie_unique');
            $table->index('institution_id');
        });

        Schema::table('evaluation_questions', function (Blueprint $table) {
            $table->foreignId('tirage_id')->nullable()->after('evaluation_id')->constrained('tirages_d_evaluation')->cascadeOnDelete();
            $table->foreignId('question_de_banque_id')->nullable()->after('tirage_id')->constrained('questions_de_banque')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('question_de_banque_id');
            $table->dropConstrainedForeignId('tirage_id');
        });
        Schema::dropIfExists('tirages_d_evaluation');
    }
};
