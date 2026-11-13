<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C28 — l'échéance d'une tentative et la copie verrouillée.
 *
 * - `ends_at` : l'échéance de la tentative, écrite au démarrage et figée. Nulle
 *   pour l'entraînement et pour toute copie d'avant ce lot : celles-ci n'ont pas
 *   d'échéance (déclaré).
 * - `status` gagne `verrouille` : la remise refusée après l'échéance.
 * - `change()` redéclare tous les modificateurs (Laravel 11+) : le défaut
 *   `en_cours` est redéclaré, l'index `status` est conservé.
 *
 * Retour arrière : une copie verrouillée redevient `en_cours` (le verrou se perd,
 * ses réponses restent), puis la colonne reprend ses trois valeurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_submissions', function (Blueprint $table): void {
            $table->dateTime('ends_at')->nullable()->after('started_at');
            $table->enum('status', ['en_cours', 'soumis', 'corrige', 'verrouille'])->default('en_cours')->change();
        });
    }

    public function down(): void
    {
        DB::table('evaluation_submissions')->where('status', 'verrouille')->update(['status' => 'en_cours']);

        Schema::table('evaluation_submissions', function (Blueprint $table): void {
            $table->enum('status', ['en_cours', 'soumis', 'corrige'])->default('en_cours')->change();
            $table->dropColumn('ends_at');
        });
    }
};
