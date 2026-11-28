<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1648 (PR A) — les filières et les niveaux d'études d'une école qui tient son catalogue.
 *
 * Une école KLASSCI les reçoit de KLASSCI ; une école autonome n'en avait aucun. Ces tables ne portent QUE
 * des lignes locales : les ids KLASSCI vivent ailleurs (`classes.filiere_id`, `matieres.filiere_id`), et les
 * deux espaces d'id ne se mélangent jamais.
 *
 * - `institution_id` NOT NULL, clé RESTRICT (déclarée dans `config/tenancy.php`).
 * - Le code est unique dans l'école, à la casse et aux espaces près : colonne générée `code_local`
 *   (`UPPER(NULLIF(TRIM(code), ''))`) et unique `(institution_id, code_local)`, le patron de #1653. La base
 *   tranche ; plusieurs lignes sans code cohabitent (NULL n'entre pas en collision).
 * - `ordre` (niveaux) : trie L1 < L2 < L3 ; nul, le niveau vient après, par libellé.
 */
return new class extends Migration
{
    private const CODE_NORMALISE = "UPPER(NULLIF(TRIM(code), ''))";

    public function up(): void
    {
        foreach (['filieres', 'niveaux_etudes'] as $table) {
            if (Schema::hasTable($table)) {
                continue;
            }

            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->id();
                $t->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
                $t->string('libelle', 191);
                $t->string('code', 50)->nullable();
                $t->string('code_local', 50)->nullable()->virtualAs(self::CODE_NORMALISE);
                if ($table === 'niveaux_etudes') {
                    $t->unsignedSmallInteger('ordre')->nullable();
                }
                $t->timestamps();
                $t->unique(['institution_id', 'code_local'], "{$table}_ecole_code_local_unique");
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('niveaux_etudes');
        Schema::dropIfExists('filieres');
    }
};
