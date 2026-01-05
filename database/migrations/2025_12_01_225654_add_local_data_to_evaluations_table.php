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
        Schema::table('evaluations', function (Blueprint $table) {
            // Cache du nom enseignant pour éviter les appels API répétés
            // Note: matiere_nom et classe_nom existent déjà (migration 2025_11_08_095412)
            $table->string('enseignant_nom')->nullable()->after('klassci_enseignant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropColumn('enseignant_nom');
        });
    }
};
