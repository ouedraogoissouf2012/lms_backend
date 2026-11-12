<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot S-matière — une séance locale ne connaît sa matière que par
 * `matiere_nom`, du texte libre. `seances.klassci_matiere_id` est l'id KLASSCI ;
 * y ranger un id local mélangerait deux espaces (D132).
 *
 * Une colonne LOCALE, `matiere_id`, sur le patron de `seances.classe_id`
 * (#846) et des ids locaux des évaluations (D72) : nullable, sans clé
 * étrangère. L'appartenance à l'établissement, et au programme de la classe,
 * est vérifiée à l'écriture (`LocalSeanceCreator`). Aucun backfill par le nom.
 *
 * @see docs/adr/2026-10-30-ecole-independante-01-meme-route-autre-source.md
 */
return new class extends Migration
{
    private const INDEX = 'seances_institution_matiere_locale_index';

    public function up(): void
    {
        if (! Schema::hasColumn('seances', 'matiere_id')) {
            Schema::table('seances', function (Blueprint $table): void {
                $table->unsignedBigInteger('matiere_id')->nullable()->after('klassci_matiere_id')
                    ->comment('S-matière — matière LOCALE. Nulle pour une séance KLASSCI, ou locale sans matière choisie.');
            });
        }

        if (! Schema::hasIndex('seances', self::INDEX)) {
            Schema::table('seances', function (Blueprint $table): void {
                $table->index(['institution_id', 'matiere_id'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        $locales = DB::table('seances')->whereNotNull('matiere_id')->count();

        if ($locales > 0) {
            throw new RuntimeException(
                "Retour arrière impossible : {$locales} séance(s) portent une matière locale, "
                .'et aucune colonne ne pourrait la conserver.'
            );
        }

        if (Schema::hasIndex('seances', self::INDEX)) {
            Schema::table('seances', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        if (Schema::hasColumn('seances', 'matiere_id')) {
            Schema::table('seances', function (Blueprint $table): void {
                $table->dropColumn('matiere_id');
            });
        }
    }
};
