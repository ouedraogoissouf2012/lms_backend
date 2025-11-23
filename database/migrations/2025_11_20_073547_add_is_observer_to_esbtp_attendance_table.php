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
        Schema::table('esbtp_attendance', function (Blueprint $table) {
            // Ajouter colonne pour marquer les observateurs (coordinateurs)
            // Les observateurs ne sont pas comptabilisés dans les statistiques de présence
            $table->boolean('is_observer')->default(false)->after('is_validated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('esbtp_attendance', function (Blueprint $table) {
            $table->dropColumn('is_observer');
        });
    }
};
