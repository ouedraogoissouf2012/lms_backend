<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1653 — le code d'une matière LOCALE est unique dans son école.
 *
 * - Seules les matières LOCALES (`klassci_id` nul) sont visées : les codes des matières miroir
 *   viennent de KLASSCI, ce n'est pas au LMS de les dédoublonner, et un unique sur elles ferait
 *   échouer la synchronisation sur une donnée qu'il ne tient pas.
 * - La base tranche, par une colonne GÉNÉRÉE `code_local` (le code d'une matière locale,
 *   débarrassé de ses blancs et en majuscules ; NULL pour un miroir ou un code vide) et un unique
 *   `(institution_id, code_local)`. Plusieurs NULL n'y entrent pas en collision (MySQL, SQLite).
 * - Majuscules : MySQL (`utf8mb4_unicode_ci`) compare déjà sans la casse, SQLite non. Comparer
 *   `UPPER(...)` rend la règle IDENTIQUE sur les deux moteurs : « bur-1 » et « BUR-1 » sont le
 *   même code. (`UPPER` de SQLite ne touche que l'ASCII ; MySQL ignore la casse au-delà.)
 * - Colonne VIRTUELLE, pas stockée : SQLite n'ajoute pas une colonne stockée par `ALTER TABLE`,
 *   et MySQL indexe une colonne virtuelle sans reconstruire la table.
 * - AVANT tout DDL, les doublons existants font échouer la migration avec leur liste, au lieu
 *   d'un 1062 opaque en production : rien n'est touché (le DDL de MySQL n'est pas
 *   transactionnel). Mesuré en production le 27/11 : aucune matière locale.
 */
return new class extends Migration
{
    private const UNIQUE = 'matieres_ecole_code_local_unique';

    private const CODE_NORMALISE = "UPPER(NULLIF(TRIM(code), ''))";

    public function up(): void
    {
        $doublons = $this->doublons();
        if ($doublons !== []) {
            throw new RuntimeException(
                count($doublons).' code(s) en double parmi les matières locales, à résoudre avant '
                .'cette migration (#1653) : '.implode(' ; ', $doublons).'.'
            );
        }

        if (! Schema::hasColumn('matieres', 'code_local')) {
            Schema::table('matieres', function (Blueprint $table): void {
                $table->string('code_local')->nullable()
                    ->virtualAs('CASE WHEN klassci_id IS NULL THEN '.self::CODE_NORMALISE.' END');
            });
        }

        if (! Schema::hasIndex('matieres', self::UNIQUE)) {
            Schema::table('matieres', function (Blueprint $table): void {
                $table->unique(['institution_id', 'code_local'], self::UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('matieres', self::UNIQUE)) {
            Schema::table('matieres', fn (Blueprint $table) => $table->dropUnique(self::UNIQUE));
        }

        if (Schema::hasColumn('matieres', 'code_local')) {
            Schema::table('matieres', fn (Blueprint $table) => $table->dropColumn('code_local'));
        }
    }

    /** @return list<string> une entrée par code en double : son école, le code, le nombre. */
    private function doublons(): array
    {
        return DB::table('matieres')
            ->whereNull('klassci_id')
            ->whereRaw(self::CODE_NORMALISE.' IS NOT NULL')
            ->selectRaw('institution_id, '.self::CODE_NORMALISE.' AS code_normalise, COUNT(*) AS nombre')
            ->groupBy('institution_id', DB::raw(self::CODE_NORMALISE))
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('institution_id')
            ->get()
            ->map(fn (object $ligne): string => sprintf(
                'école %d, code « %s » (%d fois)',
                (int) $ligne->institution_id,
                (string) $ligne->code_normalise,
                (int) $ligne->nombre,
            ))
            ->values()
            ->all();
    }
};
