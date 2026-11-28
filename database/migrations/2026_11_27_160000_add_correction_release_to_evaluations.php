<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1699 : l'enseignant choisit quand le corrigé s'ouvre (`App\Enums\CorrectionRelease`).
 *
 * Remplace le délai fixe de 7 jours après chaque remise. Une évaluation existante prend le
 * défaut, `with_results` : son corrigé s'ouvre avec ses notes, jamais avant sa fermeture pour
 * tous. Une chaîne et non un ENUM de base : la liste des modes vit dans l'enum PHP, qui valide
 * l'écriture, et la changer ne demande pas de migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluations', static function (Blueprint $table): void {
            $table->string('correction_release', 16)->default('with_results')->after('show_results');
            $table->timestamp('correction_release_at')->nullable()->after('correction_release');
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', static function (Blueprint $table): void {
            $table->dropColumn(['correction_release', 'correction_release_at']);
        });
    }
};
