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
        Schema::create('evaluation_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->onDelete('cascade');

            // Contenu de la question
            $table->text('question');
            $table->enum('type', ['qcm', 'qcm_multiple', 'vrai_faux', 'reponse_courte', 'dissertation'])->default('qcm');
            $table->integer('ordre')->default(0)->comment('Ordre d\'affichage');
            $table->decimal('points', 5, 2)->default(1.00)->comment('Points attribués');

            // Options pour QCM (JSON)
            $table->json('options')->nullable()->comment('Liste des options de réponse pour QCM');
            $table->json('correct_answers')->nullable()->comment('Réponses correctes');

            // Configuration
            $table->text('explanation')->nullable()->comment('Explication de la réponse');
            $table->boolean('is_required')->default(true);

            $table->timestamps();

            // Index
            $table->index(['evaluation_id', 'ordre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_questions');
    }
};
