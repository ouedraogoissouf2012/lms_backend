<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #717 — ne pas recycler `status` (connexion visio).
 * Marque pédagogique + origine + délégation tracée, même table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esbtp_attendance', function (Blueprint $table) {
            $table->string('mark')->nullable();
            $table->string('origin')->nullable();
            $table->unsignedBigInteger('delegated_by_id')->nullable();
            $table->timestamp('delegated_at')->nullable();
            $table->foreign('delegated_by_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('esbtp_attendance', function (Blueprint $table) {
            $table->dropForeign(['delegated_by_id']);
            $table->dropColumn(['mark', 'origin', 'delegated_by_id', 'delegated_at']);
        });
    }
};
