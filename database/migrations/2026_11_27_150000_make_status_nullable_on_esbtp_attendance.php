<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1649 — `esbtp_attendance.status` (la connexion VISIO, #717) devient nullable.
 *
 * L'appel crée une ligne pour un élève qui ne s'est jamais connecté. Avec le statut non nullable et `connected` par
 * défaut, cette ligne disait « connecté », et le rapport la comptait présente. Une ligne d'appel sans visio porte
 * désormais un statut NUL, qu'aucun compteur ne prend pour une présence (`PresenceRetenue`).
 *
 * La valeur par défaut `connected` est GARDÉE : les écrivains visio qui ne posent pas le statut (aucun aujourd'hui,
 * mais rien ne l'interdit) gardent leur comportement. Seul l'appel écrit un nul, explicitement.
 *
 * Aucune ligne existante ne change (test `PresenceRetenueTest::test_la_migration_ne_touche_aucune_ligne_existante`).
 * Le retour en arrière refuse de s'exécuter tant qu'une ligne a un statut nul : il ne fabrique pas de connexion.
 */
return new class extends Migration
{
    private const STATUTS = ['connected', 'disconnected', 'kicked'];

    public function up(): void
    {
        Schema::table('esbtp_attendance', static function (Blueprint $table): void {
            $table->enum('status', self::STATUTS)->nullable()->default('connected')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('esbtp_attendance')->whereNull('status')->exists()) {
            throw new RuntimeException('Des lignes d\'appel ont un statut visio nul : le retour en arrière en ferait des connexions.');
        }

        Schema::table('esbtp_attendance', static function (Blueprint $table): void {
            $table->enum('status', self::STATUTS)->default('connected')->change();
        });
    }
};
