<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #710 — colonnes klassci_* nullables pour la donnée locale.
 * user_classes.klassci_classe_id RESTE NOT NULL (garde structurelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_matiere_id')->nullable()->change();
            $table->unsignedBigInteger('klassci_classe_id')->nullable()->change();
        });

        Schema::table('classes', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_matiere_id')->nullable(false)->change();
            $table->unsignedBigInteger('klassci_classe_id')->nullable(false)->change();
        });

        Schema::table('classes', function (Blueprint $table): void {
            $table->unsignedBigInteger('klassci_id')->nullable(false)->change();
        });
    }
};
