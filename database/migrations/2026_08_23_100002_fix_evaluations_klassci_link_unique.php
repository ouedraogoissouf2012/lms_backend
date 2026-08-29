<?php

declare(strict_types=1);

use App\Services\Integrity\DuplicateRowRetirer;
use App\Services\Integrity\MostReferencedSurvives;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #541 — `evaluations.klassci_evaluation_id` ne portait qu'un index SIMPLE
 * (migration 2025_10_19_180924:51). Le 409 « une version en ligne existe déjà »
 * reposait donc sur un `SELECT` exécuté HORS transaction
 * (`EvaluationCreationService:82-95`), que deux requêtes concurrentes passaient
 * toutes les deux avant de créer chacune leur évaluation.
 *
 * ## Pourquoi une colonne générée
 *
 * L'invariant réel est une unicité PARTIELLE : « une seule évaluation VIVANTE par
 * (institution, évaluation KLASSCI) ». MySQL 8 ne connaît pas les index partiels,
 * et inclure `deleted_at` dans l'unique serait inopérant — les lignes vivantes y
 * portent `NULL`, et SQL autorise les `NULL` en doublon. La colonne générée
 * `klassci_link_guard` vaut `1` tant que la ligne est vivante et `NULL` dès
 * qu'elle est soft-deletée : les lignes supprimées sortent d'elles-mêmes de la
 * contrainte, ce qui préserve la recréation d'une évaluation après suppression.
 * Elle est calculée par la BASE : aucune logique applicative à maintenir, aucune
 * dérive possible via une écriture SQL brute.
 *
 * Même mécanique pour `klassci_evaluation_id NULL` (évaluation créée nativement
 * sur le LMS) : ces lignes sortent de la contrainte, donc restent illimitées —
 * comportement dont dépend `MatiereEvaluationsFetcher:151`.
 *
 * ## Pourquoi `institution_id` dans la clé
 *
 * Deux institutions ont des backends KLASSCI indépendants et peuvent porter le
 * même identifiant d'évaluation — même raison que #473 (`seances`), #258
 * (`matieres`) et `fix_classes_unique_per_institution`. Un unique global ferait
 * échouer en 409 le premier import de la seconde institution.
 *
 * ## Doublons préexistants : la survivante est celle qui porte les copies
 *
 * La ligne conservée est celle qui compte le PLUS d'`evaluation_submissions`
 * ({@see MostReferencedSurvives}). Conserver aveuglément la plus ancienne
 * retirerait l'évaluation réellement utilisée au profit d'un brouillon vide, et
 * les copies déjà notées disparaîtraient de l'interface (le scope global
 * `SoftDeletes` masquerait leur évaluation) sans aucun message.
 *
 * Les lignes retirées sont soft-deletées ET archivées intégralement dans
 * `orphan_row_archive` : une fois l'index posé, annuler un `deleted_at` le
 * violerait, l'archive est donc le seul chemin de récupération réel.
 *
 * ## Idempotence
 *
 * Sous MySQL, `ADD COLUMN` et `ADD UNIQUE` sont deux DDL à commit implicite : si
 * le second échoue (insertion concurrente recréant un doublon, lock-wait,
 * espace temporaire), la migration est marquée en échec avec la colonne DÉJÀ
 * posée. Sans garde, tout `php artisan migrate` ultérieur mourrait sur
 * « duplicate column ». Chaque étape est donc conditionnée à son absence.
 *
 * Dette tracée : les évaluations à `institution_id` NULL (antérieures au
 * multi-tenant) restent hors contrainte — les passer en NOT NULL exigerait un
 * backfill cassant, hors périmètre (cf. #583 « colonne conservée nullable »).
 *
 * @see App\Services\Integrity\DuplicateRowRetirer
 */
return new class extends Migration
{
    private const INDEX = 'evaluations_klassci_link_unique';

    private const GUARD_COLUMN = 'klassci_link_guard';

    public function up(): void
    {
        app(DuplicateRowRetirer::class)->retire(
            'evaluations',
            ['institution_id', 'klassci_evaluation_id'],
            new MostReferencedSurvives(app(DatabaseManager::class), 'evaluation_submissions', 'evaluation_id'),
            'deleted_at',
        );

        if (! Schema::hasColumn('evaluations', self::GUARD_COLUMN)) {
            Schema::table('evaluations', function (Blueprint $table): void {
                $table->unsignedTinyInteger(self::GUARD_COLUMN)
                    ->nullable()
                    ->virtualAs('case when deleted_at is null then 1 end')
                    ->comment('#541 — drapeau de vivacité, support de l\'unicité partielle du lien KLASSCI');
            });
        }

        if (! $this->hasIndex()) {
            Schema::table('evaluations', function (Blueprint $table): void {
                $table->unique(
                    ['institution_id', 'klassci_evaluation_id', self::GUARD_COLUMN],
                    self::INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex()) {
            Schema::table('evaluations', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }

        if (Schema::hasColumn('evaluations', self::GUARD_COLUMN)) {
            Schema::table('evaluations', function (Blueprint $table): void {
                $table->dropColumn(self::GUARD_COLUMN);
            });
        }
    }

    private function hasIndex(): bool
    {
        foreach (Schema::getIndexes('evaluations') as $index) {
            /** @var array{name: string} $index */
            if ($index['name'] === self::INDEX) {
                return true;
            }
        }

        return false;
    }
};
