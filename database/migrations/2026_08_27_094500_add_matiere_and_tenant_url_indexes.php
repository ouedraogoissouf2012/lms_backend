<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #549 — index manquants.
 *
 * `seances.klassci_matiere_id` n'avait aucun index (classe/enseignant oui).
 * `users.klassci_tenant_url` est filtré avec `institution_id` (dashboard admin).
 * Composites tenant-leading, comme #519.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->index(['institution_id', 'klassci_matiere_id'], 'seances_inst_matiere_idx');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index(['institution_id', 'klassci_tenant_url'], 'users_inst_tenant_url_idx');
        });
    }

    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropIndex('seances_inst_matiere_idx');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_inst_tenant_url_idx');
        });
    }
};
