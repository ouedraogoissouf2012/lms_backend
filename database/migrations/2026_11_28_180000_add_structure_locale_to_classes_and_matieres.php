<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1648 (PR B) — une classe et une matière locales portent une filière et un niveau d'études LOCAUX.
 *
 * Des colonnes PROPRES (`filiere_locale_id`, `niveau_locale_id`) avec clé étrangère, et non `filiere_id` /
 * `niveau_id` : ces deux-là portent des ids KLASSCI, sans clé étrangère possible. Deux espaces d'id ne se mélangent
 * jamais. Nullables : une classe miroir de KLASSCI n'en a pas, et aucune ligne existante n'en reçoit.
 *
 * RESTRICT : un élément employé ne se supprime pas. Le service le dit avant (409 `field_in_use` /
 * `level_in_use`) ; la base le garantit.
 *
 * ## Sous SQLite, sans clé étrangère
 *
 * Ajouter une clé étrangère à une table existante force SQLite à la RECONSTRUIRE, et la reconstruction perd la
 * colonne générée `code_local` et son unique (`2026_11_27_100000`) : deux matières au même code passaient. MESURÉ
 * en CI (UneMatiereSeModifieTest rouge sur les seules jambes SQLite). Sous SQLite, les colonnes sont donc posées
 * sans contrainte ; MySQL, la base de production, porte la clé. Le service vérifie l'école et l'emploi dans les deux.
 */
return new class extends Migration
{
    private const TABLES = ['classes', 'matieres'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'filiere_locale_id')) {
                continue;
            }

            $contraintes = DB::getDriverName() !== 'sqlite';
            Schema::table($table, function (Blueprint $t) use ($contraintes): void {
                foreach (['filiere_locale_id' => 'filieres', 'niveau_locale_id' => 'niveaux_etudes'] as $colonne => $cible) {
                    $champ = $t->foreignId($colonne)->nullable();
                    if ($contraintes) {
                        $champ->constrained($cible)->restrictOnDelete();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'filiere_locale_id')) {
                continue;
            }

            $contraintes = DB::getDriverName() !== 'sqlite';
            Schema::table($table, function (Blueprint $t) use ($contraintes): void {
                foreach (['filiere_locale_id', 'niveau_locale_id'] as $colonne) {
                    $contraintes ? $t->dropConstrainedForeignId($colonne) : $t->dropColumn($colonne);
                }
            });
        }
    }
};
