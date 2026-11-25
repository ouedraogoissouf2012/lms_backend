<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rend `institution_id` facultative le temps d'un test, puis la remet (#801).
 *
 * Pour les seuls tests qui prouvent une MIGRATION PASSÉE ou le traitement d'une ligne écrite
 * avant le NOT NULL : ils ont besoin d'une ligne sans école, que le schéma interdit désormais.
 *
 * ⚠️ Sous MySQL, un DDL auto-commite : la transaction de `RefreshDatabase` ne peut plus rien
 * annuler. La classe qui utilise ce trait efface ses lignes en `tearDown()`. Ici, seules les
 * lignes encore sans école sont retirées avant de remettre la contrainte.
 */
trait ColonneEcoleFacultativeLeTempsDuTest
{
    /** @var list<string> */
    private array $tablesFacultatives = [];

    protected function rendreFacultative(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->changerColonneEcole($table, true);
            $this->tablesFacultatives[] = $table;
        }
    }

    protected function remettreObligatoire(): void
    {
        foreach (array_reverse($this->tablesFacultatives) as $table) {
            DB::table($table)->whereNull('institution_id')->delete();
            $this->changerColonneEcole($table, false);
        }

        $this->tablesFacultatives = [];
    }

    private function changerColonneEcole(string $table, bool $facultative): void
    {
        Schema::table($table, static function (Blueprint $colonnes) use ($facultative): void {
            $colonnes->unsignedBigInteger('institution_id')->nullable($facultative)->change();
        });
    }
}
