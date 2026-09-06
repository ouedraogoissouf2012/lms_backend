<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #704 — une séance locale n'a pas d'ID KLASSCI.
 * La colonne était NOT NULL depuis la création de la table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_seance_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_seance_id')->nullable(false)->change();
        });
    }
};
