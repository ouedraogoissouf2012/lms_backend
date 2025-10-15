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
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();

            // Relation avec KLASSCI
            $table->unsignedBigInteger('matiere_id')->nullable()->comment('ID matière KLASSCI');
            $table->unsignedBigInteger('classe_id')->nullable()->comment('ID classe KLASSCI');
            $table->unsignedBigInteger('enseignant_id')->nullable()->comment('ID enseignant KLASSCI');

            // Informations du cours
            $table->string('title');
            $table->text('description')->nullable();
            $table->longText('content')->nullable(); // Contenu HTML/Markdown

            // Métadonnées
            $table->enum('type', ['cours', 'tp', 'td', 'projet', 'autre'])->default('cours');
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->integer('order')->default(0)->comment('Ordre d\'affichage');
            $table->integer('duration_minutes')->nullable()->comment('Durée estimée en minutes');

            // Dates
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            // Fichiers attachés
            $table->json('attachments')->nullable()->comment('Liste des fichiers attachés');

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('matiere_id');
            $table->index('classe_id');
            $table->index('enseignant_id');
            $table->index('status');
            $table->index(['status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
