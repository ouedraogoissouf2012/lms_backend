<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1652 (PR C) — pourquoi une adhésion s'est fermée.
 *
 * La date de fin existe déjà (`ends_on`, ADR-711-02, adhésion datée) ; il manquait le motif.
 * Le premier : `mutation`, l'élève a changé de classe dans son école. Nullable : la
 * synchronisation KLASSCI écrit cette table et n'en sait rien, et aucune ligne existante
 * n'a de motif à recevoir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('classe_etudiant', 'motif_fin')) {
            return;
        }

        Schema::table('classe_etudiant', function (Blueprint $table): void {
            $table->string('motif_fin', 40)
                ->nullable()
                ->after('ends_on')
                ->comment('#1652 — pourquoi l\'adhésion s\'est fermée (mutation) ; null sinon.');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('classe_etudiant', 'motif_fin')) {
            return;
        }

        Schema::table('classe_etudiant', function (Blueprint $table): void {
            $table->dropColumn('motif_fin');
        });
    }
};
