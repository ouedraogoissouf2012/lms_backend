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

            // Clés LOCALES, jamais des identifiants KLASSCI (#707).
            //
            // Les trois commentaires disaient « ID KLASSCI ». C'était faux, et
            // c'est ce qui a produit le défaut #707 : le résolveur d'enregistrement
            // comparait `enseignant_id` à un identifiant KLASSCI, si bien qu'une
            // vidéo pouvait être publiée dans le cours d'un autre enseignant.
            //
            // Ce que le code dit réellement :
            //   · `Lesson::matiere()`     → belongsTo(Matiere::class)  → matieres.id
            //   · `Lesson::classe()`      → belongsTo(Classe::class)   → classes.id
            //   · `Lesson::enseignant()`  → belongsTo(User::class, 'enseignant_id') → users.id
            //   · six FormRequests comparent `$lesson->enseignant_id !== $user->id`
            //
            // Le passage d'un identifiant KLASSCI à sa clé locale se fait AVANT
            // toute comparaison (`Matiere::where('klassci_id', …)->id`), jamais
            // en mélangeant les deux espaces dans un même `whereIn`.
            $table->unsignedBigInteger('matiere_id')->nullable()->comment('matieres.id (LOCAL)');
            $table->unsignedBigInteger('classe_id')->nullable()->comment('classes.id (LOCAL)');
            $table->unsignedBigInteger('enseignant_id')->nullable()->comment('users.id (LOCAL)');

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
