<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRITICAL-05 — Add `klassci_role` column to `users`.
 *
 * Stores the role value received from KLASSCI separately from `users.role`
 * (the LMS authorization source-of-truth). Closes a privilege escalation
 * vector where a compromised KLASSCI server could push `role: 'supradmin'`
 * via the 24h re-sync middleware.
 *
 * See `.claude/specs/critical-05-klassci-role-separation/` for context.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent guard — survives accidental re-runs.
        if (Schema::hasColumn('users', 'klassci_role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('klassci_role', 50)->nullable()->after('role');
            $table->index('klassci_role');
        });

        // Backfill: copy current `role` into `klassci_role` for KLASSCI-synced users.
        // Users without a `klassci_id` (seeded supradmin, service accounts) keep
        // klassci_role = NULL — they have no KLASSCI context to begin with.
        //
        // Batched by 1000-row chunks to keep the lock window short on the users
        // table at the 200k+ scale projection (design.md §10).
        //
        // NOTE (issue #126): pour les déploiements à grande échelle (>500k users)
        // où ce backfill inline risque un timeout, utiliser plutôt le command
        // artisan dédié :
        //     php artisan klassci:backfill-role --chunk=2000
        // Le command est IDEMPOTENT (sémantique `klassci_role = role` déterministe).
        DB::table('users')
            ->whereNotNull('klassci_id')
            ->orderBy('id')
            ->chunkById(1000, function ($users) {
                DB::table('users')
                    ->whereIn('id', $users->pluck('id'))
                    ->update(['klassci_role' => DB::raw('role')]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['klassci_role']);
            $table->dropColumn('klassci_role');
        });
    }
};
