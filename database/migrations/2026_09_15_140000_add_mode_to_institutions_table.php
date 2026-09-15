<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #805 — le mode d'un établissement devient une donnée DÉCLARÉE.
 *
 * NOT NULL, défaut `klassci`, aucune reprise de données : toute ligne existante
 * garde exactement le comportement d'aujourd'hui. C'est la condition du « zéro
 * régression » promis aux écoles déjà branchées.
 *
 * Le mode n'est pas déduit de `klassci_api_url` : une colonne nullable ne sait
 * pas distinguer « pas encore configuré » de « délibérément autonome », et
 * l'assimilation ferait d'une faute de saisie un changement de mode en
 * production (PLAN_AUTONOMIE_KLASSCI §4.1 et §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->string('mode', 20)->default('klassci')->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });
    }
};
