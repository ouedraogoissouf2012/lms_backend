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
        Schema::table('seances', function (Blueprint $table) {
            // Champ pour marquer si la séance est active (visible) ou archivée
            $table->boolean('is_active')->default(true)->after('visio_active');

            // Date d'archivage
            $table->timestamp('archived_at')->nullable()->after('is_active');

            // Raison de l'archivage (supprimee_klassci, trop_ancienne, archivage_manuel)
            $table->string('archive_reason', 50)->nullable()->after('archived_at');

            // Index pour améliorer les performances des requêtes
            $table->index('is_active');
            $table->index(['is_active', 'archived_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table) {
            $table->dropIndex(['seances_is_active_index']);
            $table->dropIndex(['seances_is_active_archived_at_index']);
            $table->dropColumn(['is_active', 'archived_at', 'archive_reason']);
        });
    }
};
