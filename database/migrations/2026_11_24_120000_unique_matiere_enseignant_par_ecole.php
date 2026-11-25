<?php

declare(strict_types=1);

use App\Services\Tenancy\GardeDesDoublonsDeMatiereEnseignant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #801 (PR 4) — un lien enseignant ↔ matière KLASSCI est unique DANS son école.
 *
 * L'ancien unique `(klassci_matiere_id, klassci_enseignant_id, annee_universitaire_id)` est
 * REMPLACÉ par `(institution_id, klassci_matiere_id, klassci_enseignant_id)` :
 * - il ne dédoublonnait rien : le seul écrivain, `TeacherMatieresLinker`, laisse l'année à NULL,
 *   et SQL ne dédoublonne pas les NULL ;
 * - il ignorait l'école : deux écoles KLASSCI (instances distinctes, identifiants qui se
 *   recouvrent) se seraient bloquées dès qu'une année était posée.
 * Le nouvel unique est la clé de rapprochement explicite du linker. `institution_id` est NOT NULL
 * depuis #1530. La colonne `annee_universitaire_id` reste.
 *
 * Ordre : la garde, puis le NOUVEL index, puis seulement le retrait de l'ancien (aucune clé
 * étrangère ne s'appuie sur l'ancien, qui commence par `klassci_matiere_id` ; l'ordre reste sûr
 * si une telle clé existait). Rejouable : chaque index n'est touché que s'il est dans l'état
 * contraire.
 */
return new class extends Migration
{
    private const TABLE = 'matiere_enseignant';

    private const NOUVEL = 'matiere_enseignant_ecole_matiere_enseignant_unique';

    private const ANCIEN = 'unique_matiere_enseignant_annee';

    public function up(): void
    {
        app(GardeDesDoublonsDeMatiereEnseignant::class)->exigerAucunDoublon();

        if (! $this->present(self::NOUVEL)) {
            Schema::table(self::TABLE, static function (Blueprint $index): void {
                $index->unique(['institution_id', 'klassci_matiere_id', 'klassci_enseignant_id'], self::NOUVEL);
            });
        }

        if ($this->present(self::ANCIEN)) {
            Schema::table(self::TABLE, static function (Blueprint $index): void {
                $index->dropUnique(self::ANCIEN);
            });
        }
    }

    /**
     * L'ancien unique d'abord, le nouveau ensuite. Remettre l'ancien échoue si deux écoles portent
     * le même lien avec la même année non nulle : c'est le cas que cette migration rend possible.
     */
    public function down(): void
    {
        if (! $this->present(self::ANCIEN)) {
            Schema::table(self::TABLE, static function (Blueprint $index): void {
                $index->unique(['klassci_matiere_id', 'klassci_enseignant_id', 'annee_universitaire_id'], self::ANCIEN);
            });
        }

        if ($this->present(self::NOUVEL)) {
            Schema::table(self::TABLE, static function (Blueprint $index): void {
                $index->dropUnique(self::NOUVEL);
            });
        }
    }

    private function present(string $nom): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if ($index['name'] === $nom) {
                return true;
            }
        }

        return false;
    }
};
