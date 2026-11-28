<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

            Schema::table($table, function (Blueprint $t): void {
                $t->foreignId('filiere_locale_id')->nullable()->constrained('filieres')->restrictOnDelete();
                $t->foreignId('niveau_locale_id')->nullable()->constrained('niveaux_etudes')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'filiere_locale_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t): void {
                $t->dropConstrainedForeignId('filiere_locale_id');
                $t->dropConstrainedForeignId('niveau_locale_id');
            });
        }
    }
};
