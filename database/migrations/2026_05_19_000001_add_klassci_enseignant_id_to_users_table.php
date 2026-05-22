<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #119 — Add `klassci_enseignant_id` column to `users`.
 *
 * Stores the KLASSCI teacher identifier separately from the volatile
 * `users.klassci_data` blob (which is overwritten by every 24h re-sync via
 * `EnsureKlassciSync`). The dedicated column becomes the source of authority
 * for evaluation ownership checks. Closes an IDOR vector where a compromised
 * KLASSCI server could push `enseignant_id: <victim>` and let a user
 * delete/publish/update another teacher's evaluations.
 *
 * Pattern identique à la PR #118 (CRITICAL-05 — `klassci_role` separation).
 *
 * See `.claude/specs/klassci-enseignant-id-separation/` for full context.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent guard — survives accidental re-runs.
        if (Schema::hasColumn('users', 'klassci_enseignant_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('klassci_enseignant_id')->nullable()->after('klassci_role');
            $table->index('klassci_enseignant_id');
        });

        // Backfill: extract `enseignant_id` from the existing `klassci_data` JSON
        // blob for KLASSCI-synced users. Done in PHP (foreach) rather than via
        // DB JSON path syntax to keep the migration portable across SQLite,
        // MySQL, and PostgreSQL (cf. design.md §2.1).
        //
        // Batched by 1000-row chunks to keep the lock window short on the
        // users table at the 200k+ scale projection (design.md §10).
        //
        // NOTE (issue #126): pour les déploiements à grande échelle (>500k users)
        // où ce backfill inline risque un timeout, utiliser plutôt le command
        // artisan dédié :
        //     php artisan klassci:backfill-enseignant-id --chunk=2000
        // Le command est IDEMPOTENT (filtre `whereNull('klassci_enseignant_id')`).
        DB::table('users')
            ->whereNotNull('klassci_id')
            ->whereNull('klassci_enseignant_id')
            ->orderBy('id')
            ->chunkById(1000, function ($users) {
                foreach ($users as $u) {
                    $blob = is_string($u->klassci_data)
                        ? json_decode($u->klassci_data, true)
                        : (array) $u->klassci_data;
                    $enseignantId = data_get($blob, 'enseignant_id');
                    if (is_numeric($enseignantId)) {
                        DB::table('users')->where('id', $u->id)->update([
                            'klassci_enseignant_id' => (int) $enseignantId,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['klassci_enseignant_id']);
            $table->dropColumn('klassci_enseignant_id');
        });
    }
};
