<?php

declare(strict_types=1);

use App\Services\Integrity\DuplicateRowRetirer;
use App\Services\Integrity\PreferredValueSurvives;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #541 — `classe_etudiant` portait
 * `unique(classe_id, user_id, annee_universitaire_id)`
 * (2025_10_14_160400:110), mais le synchroniseur n'a JAMAIS écrit
 * `annee_universitaire_id` (`ClasseStudentsSynchronizer:179-187`). La colonne
 * restant `NULL`, et SQL autorisant les `NULL` en doublon dans un index unique,
 * la contrainte n'a jamais rien contraint : deux inscriptions concurrentes du
 * même étudiant dans la même classe passaient toutes les deux le
 * `SELECT ... exists()` puis inséraient (TOCTOU).
 *
 * ## Clé naturelle retenue : `(classe_id, user_id)`
 *
 * Une ligne `classes` porte DÉJÀ son année universitaire
 * (2025_10_14_160400:59, alimentée par `ClasseSyncService:221`) : une classe est
 * donc un objet déjà daté, et l'année dans le pivot est redondante avec
 * `classe_id`. Les deux colonnes retenues sont `NOT NULL` (`foreignId`), donc
 * l'unique est cette fois EFFECTIVE. `institution_id` n'a pas à figurer dans la
 * clé : `classe_id` référence une clé primaire locale, déjà rattachée à une
 * institution.
 *
 * `annee_universitaire_id` est conservée (colonne informative) et désormais
 * alimentée par le synchroniseur, pour qu'elle cesse d'être un champ mort.
 *
 * ## Doublons préexistants : l'inscription ACTIVE survit
 *
 * L'ancien index tolérait deux lignes pour un même (classe, étudiant) dès que
 * leurs années différaient — typiquement une `abandonne` historique et une
 * `actif` courante. Conserver la plus ancienne ferait sortir l'étudiant de sa
 * propre classe : `Classe::etudiantsActifs()` filtre `statut = 'actif'` sur ce
 * pivot. La survivante est donc celle qui vaut `actif`
 * ({@see PreferredValueSurvives}), départagée ensuite par récence puis par id.
 *
 * Ce pivot n'ayant pas de soft delete, les lignes excédentaires sont archivées
 * INTÉGRALEMENT dans `orphan_row_archive` avant suppression (réversibilité).
 *
 * ## Idempotence et ordre des opérations
 *
 * L'unique de remplacement est posé AVANT que l'ancien ne soit retiré : sous
 * MySQL chaque DDL auto-commite, et l'ordre inverse laisserait la table SANS
 * aucune unicité si le second échouait — fenêtre pendant laquelle un sync en
 * cours peut réinsérer un doublon, rendant la migration définitivement
 * injouable. Chaque étape est conditionnée à l'état réel du schéma, donc
 * rejouable telle quelle.
 *
 * @see App\Services\Integrity\DuplicateRowRetirer
 */
return new class extends Migration
{
    private const OLD_INDEX = 'unique_classe_user_annee';

    private const NEW_INDEX = 'classe_etudiant_classe_user_unique';

    public function up(): void
    {
        app(DuplicateRowRetirer::class)->retire(
            'classe_etudiant',
            ['classe_id', 'user_id'],
            new PreferredValueSurvives('statut', 'actif'),
        );

        if (! $this->hasIndex(self::NEW_INDEX)) {
            Schema::table('classe_etudiant', function (Blueprint $table): void {
                $table->unique(['classe_id', 'user_id'], self::NEW_INDEX);
            });
        }

        if ($this->hasIndex(self::OLD_INDEX)) {
            Schema::table('classe_etudiant', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! $this->hasIndex(self::OLD_INDEX)) {
            Schema::table('classe_etudiant', function (Blueprint $table): void {
                $table->unique(['classe_id', 'user_id', 'annee_universitaire_id'], self::OLD_INDEX);
            });
        }

        if ($this->hasIndex(self::NEW_INDEX)) {
            Schema::table('classe_etudiant', function (Blueprint $table): void {
                $table->dropUnique(self::NEW_INDEX);
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('classe_etudiant') as $index) {
            /** @var array{name: string} $index */
            if ($index['name'] === $name) {
                return true;
            }
        }

        return false;
    }
};
