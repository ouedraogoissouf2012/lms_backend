<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #767 — un tenant peut exister avant (ou sans) liaison KLASSCI.
 * La validation était déjà nullable ; la colonne NOT NULL produisait un 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->string('klassci_api_url', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->string('klassci_api_url', 500)->nullable(false)->change();
        });
    }
};
