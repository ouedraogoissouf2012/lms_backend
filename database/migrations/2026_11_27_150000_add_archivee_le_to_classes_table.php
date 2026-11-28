<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1652 (PR B) — une classe locale s'archive.
 *
 * Une DATE, pas un booléen : elle dit quand, et `null` dit « active » sans seconde colonne à
 * tenir d'accord. Rien n'est supprimé : les élèves, les notes et les présences d'une classe
 * archivée restent lisibles ; seule l'entrée de nouveaux élèves est refusée
 * (`StudentEnrolmentService`, `ClasseOuverteParCode`).
 *
 * Colonne ajoutée nulle : aucune ligne existante ne change d'état, aucun remplissage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('classes', 'archivee_le')) {
            return;
        }

        Schema::table('classes', function (Blueprint $table): void {
            $table->timestamp('archivee_le')
                ->nullable()
                ->after('code_inscription_revoque_le')
                ->comment('#1652 — archivée à cette date : plus aucune inscription ; null = active.');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('classes', 'archivee_le')) {
            return;
        }

        Schema::table('classes', function (Blueprint $table): void {
            $table->dropColumn('archivee_le');
        });
    }
};
