<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1806 — une période de formation se découpe en sous-périodes (semestres, trimestres).
 *
 * - `training_session_id` en CASCADE : une sous-période n'a pas de sens sans sa période (une période ne se
 *   supprime qu'en brouillon, #1651).
 * - `institution_id` NOT NULL, clé RESTRICT, déclarée dans `config/tenancy.php`.
 * - `debut <= fin` est garanti par la BASE, comme l'ordre des dates d'une période (`2026_09_16_090000`) :
 *   contrainte CHECK sous MySQL, déclencheurs sous SQLite (qui ne sait pas ajouter une contrainte à une table
 *   existante). Les bornes de la période et le non-chevauchement entre sœurs dépendent d'autres lignes : le
 *   service les vérifie sous le verrou de la période.
 */
return new class extends Migration
{
    private const ORDRE = 'debut <= fin';

    public function up(): void
    {
        if (Schema::hasTable('sous_periodes')) {
            return;
        }

        Schema::create('sous_periodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->restrictOnDelete();
            $table->foreignId('training_session_id')->constrained('training_sessions')->cascadeOnDelete();
            $table->string('libelle', 191);
            $table->date('debut');
            $table->date('fin');
            $table->unsignedSmallInteger('ordre')->nullable();
            $table->timestamps();
            $table->index(['training_session_id', 'debut']);
        });

        $this->poserLaContrainteDOrdre();
    }

    public function down(): void
    {
        Schema::dropIfExists('sous_periodes');
    }

    private function poserLaContrainteDOrdre(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $evenement) {
                DB::statement(sprintf(
                    'CREATE TRIGGER sous_periodes_ordre_%s BEFORE %s ON sous_periodes FOR EACH ROW WHEN NOT (%s) '
                    ."BEGIN SELECT RAISE(ABORT, 'sous_periodes : le debut precede la fin'); END",
                    $evenement,
                    strtoupper($evenement),
                    'NEW.debut <= NEW.fin',
                ));
            }

            return;
        }

        DB::statement('ALTER TABLE sous_periodes ADD CONSTRAINT sous_periodes_ordre CHECK ('.self::ORDRE.')');
    }
};
