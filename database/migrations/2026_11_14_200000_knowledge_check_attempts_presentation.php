<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C44 — la tentative de quiz de leçon naît au démarrage et garde sa présentation.
 *
 * - `presentation` : l'ordre présenté à l'élève, en indices d'origine (les
 *   questions, puis les options de chaque question). Nulle pour toute tentative
 *   d'avant ce lot : aucune permutation n'avait été écrite, ses notes ne peuvent
 *   pas être recalculées (déclaré).
 * - `score` devient nullable : une tentative OUVERTE (`completed_at` nul) n'a pas
 *   encore de note. `change()` redéclare tous les modificateurs (Laravel 11+) :
 *   le commentaire est redéclaré. Les lignes existantes sont toutes remises
 *   (`completed_at` renseigné) : aucune n'est touchée.
 *
 * Retour arrière : les tentatives ouvertes (démarrées, jamais remises, sans note)
 * sont supprimées, puis `score` redevient NOT NULL et la colonne part.
 *
 * Idempotente dans les deux sens : la colonne n'est ajoutée ou retirée que si
 * besoin ; redéclarer `score` est sans effet quand il l'est déjà.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('knowledge_check_attempts', 'presentation')) {
            Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
                $table->json('presentation')->nullable()->after('answers');
            });
        }

        Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
            $table->integer('score')->nullable()->comment('Score obtenu en pourcentage')->change();
        });
    }

    public function down(): void
    {
        DB::table('knowledge_check_attempts')->whereNull('completed_at')->delete();

        Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
            $table->integer('score')->comment('Score obtenu en pourcentage')->change();
        });

        if (Schema::hasColumn('knowledge_check_attempts', 'presentation')) {
            Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
                $table->dropColumn('presentation');
            });
        }
    }
};
