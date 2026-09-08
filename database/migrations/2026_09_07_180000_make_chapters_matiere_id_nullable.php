<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #740 — `chapters.matiere_id` devient nullable, comme `lessons.matiere_id`.
 *
 * ## Le défaut, révélé par la jambe MySQL de la CI
 *
 * La colonne a été créée NOT NULL (`2025_10_25_202933`), mais la
 * reconstruction de table SQLite de `2026_01_03_220000` l'a redéclarée
 * `matiere_id INTEGER` — donc NULLABLE. **Les deux moteurs n'avaient plus le
 * même schéma**, et seule la jambe MySQL pouvait le dire.
 *
 * Or `ChapterCrudService` y recopie `$lesson->matiere_id`, et
 * `lessons.matiere_id` EST nullable : une leçon sans matière — cas légitime,
 * il en existe en base — produisait un **500** à la création de son premier
 * chapitre, sous MySQL uniquement. Invisible en local, invisible sous SQLite.
 *
 * Le sens de la correction n'est pas arbitraire : le chapitre hérite la
 * matière de sa leçon. Il ne peut pas être plus contraint qu'elle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table): void {
            $table->unsignedBigInteger('matiere_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volontairement non réversible : remettre NOT NULL échouerait sur
        // toute ligne héritée à null, et rétablirait le 500.
    }
};
