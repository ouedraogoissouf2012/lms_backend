<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1654 (PR A) — l'arbre LMD d'une école qui tient son catalogue : domaines → mentions → parcours.
 *
 * - `institution_id` NOT NULL, clé RESTRICT (déclarée dans `config/tenancy.php`) ; parents en RESTRICT : un élément
 *   ne se supprime pas, il se DÉSACTIVE (`actif`), car un parcours a pu porter des classes.
 * - Le code est facultatif ; donné, il est unique PAR NIVEAU et par école, à la casse et aux espaces près : colonne
 *   générée `code_local` (`UPPER(NULLIF(TRIM(code), ''))`) et unique `(institution_id, code_local)` par table. La
 *   base tranche ; plusieurs éléments sans code cohabitent.
 */
return new class extends Migration
{
    private const CODE_NORMALISE = "UPPER(NULLIF(TRIM(code), ''))";

    /** @var array<string, array{0: string, 1: string}|null> table => [colonne du parent, table du parent] */
    private const NIVEAUX = [
        'lmd_domaines' => null,
        'lmd_mentions' => ['domaine_id', 'lmd_domaines'],
        'lmd_parcours' => ['mention_id', 'lmd_mentions'],
    ];

    public function up(): void
    {
        foreach (self::NIVEAUX as $table => $parent) {
            if (Schema::hasTable($table)) {
                continue;
            }

            Schema::create($table, function (Blueprint $t) use ($table, $parent): void {
                $t->id();
                $t->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
                if ($parent !== null) {
                    $t->foreignId($parent[0])->constrained($parent[1])->restrictOnDelete();
                }
                $t->string('libelle', 191);
                $t->string('code', 50)->nullable();
                $t->string('code_local', 50)->nullable()->virtualAs(self::CODE_NORMALISE);
                $t->boolean('actif')->default(true);
                $t->timestamps();
                $t->unique(['institution_id', 'code_local'], "{$table}_ecole_code_local_unique");
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::NIVEAUX)) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
