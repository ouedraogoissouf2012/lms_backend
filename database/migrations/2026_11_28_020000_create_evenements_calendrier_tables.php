<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1671 — le calendrier de l'année d'une école (ARRÊT 1 validé le 28/11).
 *
 * - `evenements_calendrier` : une table par école (déclarée dans `tenancy.institution_scoped_tables`, clé de l'école
 *   en RESTRICT). `debut` et `fin` sont des JOURS ; le type suit l'énumération `TypeDEvenement`.
 * - `evenement_calendrier_classe` : la portée. Aucune ligne : toute l'école. Cascade des deux côtés : une classe
 *   supprimée sort de la portée, un événement supprimé emporte sa portée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evenements_calendrier', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('titre', 150);
            $table->date('debut');
            $table->date('fin');
            $table->timestamps();

            $table->index(['institution_id', 'debut', 'fin']);
        });

        Schema::create('evenement_calendrier_classe', static function (Blueprint $table): void {
            $table->foreignId('evenement_calendrier_id')->constrained('evenements_calendrier')->cascadeOnDelete();
            $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();

            $table->primary(['evenement_calendrier_id', 'classe_id']);
            $table->index('classe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evenement_calendrier_classe');
        Schema::dropIfExists('evenements_calendrier');
    }
};
