<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #258 — La table `matieres` était `klassci_id->unique()` GLOBAL, alors
 * qu'en multi-tenant deux institutions peuvent partager le même `klassci_id`
 * (espaces d'ID KLASSCI indépendants par tenant). Le sync de la 2e institution
 * violait la contrainte. On passe à un unique composite (klassci_id, institution_id).
 *
 * NB : le même défaut existe sur `classes.klassci_id` (hors périmètre #258).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matieres', function (Blueprint $table) {
            // Unique global posé par `->unique()` dans la migration d'origine.
            $table->dropUnique('matieres_klassci_id_unique');
            // Unicité réelle : une matière KLASSCI par institution.
            $table->unique(['klassci_id', 'institution_id'], 'matieres_klassci_institution_unique');
        });
    }

    public function down(): void
    {
        Schema::table('matieres', function (Blueprint $table) {
            $table->dropUnique('matieres_klassci_institution_unique');
            $table->unique('klassci_id', 'matieres_klassci_id_unique');
        });
    }
};
