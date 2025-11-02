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
        Schema::create('evaluation_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('klassci_etudiant_id')->comment('ID de l\'étudiant dans KLASSCI');

            // Informations de soumission
            $table->integer('attempt')->default(1)->comment('Numéro de la tentative');
            $table->enum('status', ['en_cours', 'soumis', 'corrige'])->default('en_cours');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('submitted_at')->nullable();

            // Réponses de l'étudiant (JSON)
            $table->json('answers')->nullable()->comment('Réponses de l\'étudiant');

            // Résultats
            $table->decimal('score', 5, 2)->nullable()->comment('Score obtenu');
            $table->decimal('note_sur_20', 5, 2)->nullable()->comment('Note finale sur 20');
            $table->text('feedback')->nullable()->comment('Commentaire global');

            // Synchronisation KLASSCI
            $table->boolean('synced_to_klassci')->default(false);
            $table->dateTime('synced_at')->nullable();

            $table->timestamps();

            // Index
            $table->index(['evaluation_id', 'klassci_etudiant_id']);
            $table->index('status');
            $table->unique(['evaluation_id', 'klassci_etudiant_id', 'attempt'], 'eval_sub_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_submissions');
    }
};
