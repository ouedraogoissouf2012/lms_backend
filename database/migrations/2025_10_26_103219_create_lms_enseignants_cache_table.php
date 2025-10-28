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
        Schema::create('lms_enseignants_cache', function (Blueprint $table) {
            $table->id();
            $table->integer('enseignant_id')->unique()->comment('ID de l\'enseignant KLASSCI');
            $table->json('data')->comment('Données JSON complètes de KLASSCI (matieres, classes, stats)');
            $table->timestamp('synced_at')->comment('Date de dernière synchronisation avec KLASSCI');
            $table->timestamp('expires_at')->comment('Date d\'expiration du cache');
            $table->timestamps();

            $table->index('enseignant_id');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_enseignants_cache');
    }
};
