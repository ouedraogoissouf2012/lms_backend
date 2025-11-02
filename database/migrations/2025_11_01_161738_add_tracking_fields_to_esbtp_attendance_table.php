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
            // Champ pour le heartbeat (dernier ping)
            $table->timestamp('last_seen_at')->nullable()->after('left_at');

            // Statut de connexion
            $table->enum('status', ['connected', 'disconnected', 'kicked'])
                  ->default('connected')
                  ->after('is_validated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('esbtp_attendance', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'status']);
        });
    }
};
