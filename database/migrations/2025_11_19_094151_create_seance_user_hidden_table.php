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
        Schema::create('seance_user_hidden', function (Blueprint $table) {
            $table->id();

            // Clés étrangères
            $table->unsignedBigInteger('seance_id');
            $table->unsignedBigInteger('user_id');

            // Date de masquage
            $table->timestamp('hidden_at')->useCurrent();

            $table->timestamps();

            // Contraintes
            $table->foreign('seance_id')->references('id')->on('seances')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Index unique pour éviter les doublons
            $table->unique(['seance_id', 'user_id']);

            // Index pour les requêtes
            $table->index('user_id');
            $table->index('seance_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seance_user_hidden');
    }
};
