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
        Schema::create('user_classes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->integer('klassci_classe_id');
            $table->string('classe_nom')->nullable();
            $table->string('classe_libelle')->nullable();
            $table->json('classe_data')->nullable(); // Pour stocker toutes les infos de la classe
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Index pour recherche rapide
            $table->index('user_id');
            $table->index('klassci_classe_id');
            $table->unique(['user_id', 'klassci_classe_id']); // Un étudiant ne peut être dans la même classe qu'une fois
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_classes');
    }
};
