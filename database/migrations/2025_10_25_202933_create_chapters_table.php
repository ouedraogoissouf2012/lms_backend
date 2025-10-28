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
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->unsignedBigInteger('matiere_id')->comment('ID matière KLASSCI');
            $table->unsignedBigInteger('enseignant_id')->nullable()->comment('ID enseignant créateur');

            // Informations du chapitre
            $table->string('title');
            $table->text('description')->nullable();

            // Ordre et métadonnées
            $table->integer('order')->default(0)->comment('Ordre d\'affichage');
            $table->integer('duration_minutes')->nullable()->comment('Durée totale estimée');

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Index
            $table->index('matiere_id');
            $table->index('enseignant_id');
            $table->index('order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
