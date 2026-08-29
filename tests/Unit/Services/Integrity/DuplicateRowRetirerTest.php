<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integrity;

use App\Services\Integrity\ArchivedRowWriter;
use App\Services\Integrity\DuplicateRowRetirer;
use App\Services\Integrity\MostReferencedSurvives;
use App\Services\Integrity\OldestSurvives;
use App\Services\Integrity\PreferredValueSurvives;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

/**
 * Retrait des doublons préexistants avant pose d'un index unique (#541 — R1/R2/R4).
 *
 * Deux modes, une seule responsabilité (« retirer des doublons sans perte ») :
 * soft delete quand la table le permet, suppression sinon — et dans les DEUX cas
 * archivage intégral préalable.
 */
#[CoversClass(DuplicateRowRetirer::class)]
final class DuplicateRowRetirerTest extends IntegrityProbeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->db->connection()->getSchemaBuilder();

        $schema->create('probe_evaluations', static function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('institution_id')->nullable();
            $t->unsignedBigInteger('klassci_evaluation_id')->nullable();
            $t->string('titre')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->softDeletes();
        });

        $schema->create('probe_submissions', static function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('evaluation_id');
        });

        $schema->create('probe_pivot', static function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('classe_id');
            $t->unsignedBigInteger('user_id');
            $t->string('statut')->default('actif');
            $t->timestamp('updated_at')->nullable();
        });
    }

    // ------------------------------------------------ politique de survie

    public function test_the_evaluation_carrying_the_submissions_survives(): void
    {
        // Le cas qui coûte cher : le brouillon vide est le PLUS ANCIEN, la vraie
        // évaluation notée est la plus récente. Garder « la plus ancienne »
        // masquerait les copies derrière une ligne soft-deletée.
        $this->seedEvaluations([
            ['id' => 1, 'institution_id' => 1, 'klassci_evaluation_id' => 42, 'titre' => 'Brouillon vide'],
            ['id' => 2, 'institution_id' => 1, 'klassci_evaluation_id' => 42, 'titre' => 'Celle utilisée'],
        ]);
        $this->db->table('probe_submissions')->insert([
            ['id' => 1, 'evaluation_id' => 2],
            ['id' => 2, 'evaluation_id' => 2],
        ]);

        $retired = $this->retirer()->retire(
            'probe_evaluations',
            ['institution_id', 'klassci_evaluation_id'],
            new MostReferencedSurvives($this->db, 'probe_submissions', 'evaluation_id'),
            'deleted_at',
        );

        self::assertSame(1, $retired);
        self::assertSame([2], $this->liveEvaluationIds(), 'L’évaluation portant les copies doit survivre.');
    }

    public function test_the_active_enrollment_survives_over_the_abandoned_one(): void
    {
        $this->db->table('probe_pivot')->insert([
            ['id' => 1, 'classe_id' => 5, 'user_id' => 9, 'statut' => 'abandonne', 'updated_at' => null],
            ['id' => 2, 'classe_id' => 5, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => null],
        ]);

        $retired = $this->retirer()->retire(
            'probe_pivot',
            ['classe_id', 'user_id'],
            new PreferredValueSurvives('statut', 'actif'),
        );

        self::assertSame(1, $retired);
        self::assertSame(
            [2],
            $this->db->table('probe_pivot')->orderBy('id')->pluck('id')->map(intval(...))->all(),
            'Conserver l’inscription abandonnée sortirait l’étudiant de sa classe.',
        );
    }

    public function test_recency_breaks_ties_before_the_identifier(): void
    {
        $this->db->table('probe_pivot')->insert([
            ['id' => 1, 'classe_id' => 5, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'classe_id' => 5, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => '2026-08-01 00:00:00'],
        ]);

        $this->retirer()->retire('probe_pivot', ['classe_id', 'user_id'], new PreferredValueSurvives('statut', 'actif'));

        self::assertSame([2], $this->db->table('probe_pivot')->pluck('id')->map(intval(...))->all());
    }

    // ------------------------------------------------------------ modes

    public function test_soft_delete_mode_archives_before_retiring(): void
    {
        $this->seedEvaluations([
            ['id' => 1, 'institution_id' => 1, 'klassci_evaluation_id' => 42, 'titre' => 'A'],
            ['id' => 2, 'institution_id' => 1, 'klassci_evaluation_id' => 42, 'titre' => 'B'],
            ['id' => 3, 'institution_id' => 2, 'klassci_evaluation_id' => 42, 'titre' => 'Autre tenant'],
        ]);

        $retired = $this->retirer()->retire(
            'probe_evaluations',
            ['institution_id', 'klassci_evaluation_id'],
            new OldestSurvives(),
            'deleted_at',
        );

        self::assertSame(1, $retired);
        self::assertSame([1, 3], $this->liveEvaluationIds(), 'Les tenants restent indépendants.');

        // Le soft delete NE SUFFIT PAS : une fois l'index unique posé, annuler le
        // `deleted_at` le violerait. L'archive est le seul recours réel.
        $archived = $this->archived();
        self::assertCount(1, $archived);
        self::assertSame(2, (int) $archived[0]['source_row_id']);
        self::assertSame('duplicate:probe_evaluations(institution_id,klassci_evaluation_id)', $archived[0]['reason']);

        $payload = json_decode((string) $archived[0]['payload'], true);
        self::assertIsArray($payload);
        self::assertSame('B', $payload['titre']);
    }

    public function test_hard_mode_archives_then_deletes_the_extra_rows(): void
    {
        $this->db->table('probe_pivot')->insert([
            ['id' => 1, 'classe_id' => 5, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => null],
            ['id' => 2, 'classe_id' => 5, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => null],
            ['id' => 3, 'classe_id' => 6, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => null],
        ]);

        $retired = $this->retirer()->retire('probe_pivot', ['classe_id', 'user_id'], new OldestSurvives());

        self::assertSame(1, $retired);
        self::assertSame([1, 3], $this->db->table('probe_pivot')->orderBy('id')->pluck('id')->map(intval(...))->all());
        self::assertCount(1, $this->archived());
        self::assertSame('duplicate:probe_pivot(classe_id,user_id)', $this->archived()[0]['reason']);
    }

    // ---------------------------------------------------------- garde-fous

    public function test_rows_already_trashed_are_ignored(): void
    {
        $this->seedEvaluations([
            ['id' => 1, 'institution_id' => 1, 'klassci_evaluation_id' => 42, 'deleted_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'institution_id' => 1, 'klassci_evaluation_id' => 42],
        ]);

        self::assertSame(0, $this->retireEvaluations());
        self::assertSame([2], $this->liveEvaluationIds(), 'Une seule ligne vivante : rien à retirer.');
    }

    public function test_rows_carrying_a_null_key_column_are_left_untouched(): void
    {
        $this->seedEvaluations([
            ['id' => 1, 'institution_id' => 1, 'klassci_evaluation_id' => null],
            ['id' => 2, 'institution_id' => 1, 'klassci_evaluation_id' => null],
            ['id' => 3, 'institution_id' => null, 'klassci_evaluation_id' => 42],
            ['id' => 4, 'institution_id' => null, 'klassci_evaluation_id' => 42],
        ]);

        self::assertSame(0, $this->retireEvaluations());
        self::assertSame([1, 2, 3, 4], $this->liveEvaluationIds(), 'Les NULL sortent de l’index unique.');
    }

    public function test_every_duplicated_group_is_retired_even_beyond_one_page(): void
    {
        // Régression : `duplicatedGroups()` est paginée. Traiter un seul lot
        // laisserait des doublons derrière, et l'index unique que cette purge
        // prépare REFUSERAIT alors de se poser.
        $groups = DuplicateRowRetirer::GROUP_CHUNK + 3;
        $rows = [];

        for ($classeId = 1; $classeId <= $groups; $classeId++) {
            $rows[] = ['classe_id' => $classeId, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => null];
            $rows[] = ['classe_id' => $classeId, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => null];
        }

        $this->db->table('probe_pivot')->insert($rows);

        $retired = $this->retirer()->retire('probe_pivot', ['classe_id', 'user_id'], new OldestSurvives());

        self::assertSame($groups, $retired);
        self::assertSame($groups, $this->db->table('probe_pivot')->count(), 'Il doit rester une ligne par groupe.');
        self::assertCount($groups, $this->archived());
    }

    public function test_replaying_is_a_no_op(): void
    {
        $this->db->table('probe_pivot')->insert([
            ['id' => 1, 'classe_id' => 5, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => null],
            ['id' => 2, 'classe_id' => 5, 'user_id' => 9, 'statut' => 'actif', 'updated_at' => null],
        ]);

        $this->retirer()->retire('probe_pivot', ['classe_id', 'user_id'], new OldestSurvives());

        self::assertSame(0, $this->retirer()->retire('probe_pivot', ['classe_id', 'user_id'], new OldestSurvives()));
        self::assertCount(1, $this->archived());
    }

    // ----------------------------------------------------------- helpers

    private function retireEvaluations(): int
    {
        return $this->retirer()->retire(
            'probe_evaluations',
            ['institution_id', 'klassci_evaluation_id'],
            new OldestSurvives(),
            'deleted_at',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function seedEvaluations(array $rows): void
    {
        // Colonnes normalisées : un insert par lot exige des clés identiques sur
        // toutes les lignes (« all VALUES must have the same number of terms »).
        $this->db->table('probe_evaluations')->insert(array_map(
            static fn (array $row): array => $row + ['deleted_at' => null, 'titre' => null, 'updated_at' => null],
            $rows,
        ));
    }

    /**
     * @return list<int>
     */
    private function liveEvaluationIds(): array
    {
        return $this->db->table('probe_evaluations')
            ->whereNull('deleted_at')->orderBy('id')->pluck('id')->map(intval(...))->all();
    }

    private function retirer(): DuplicateRowRetirer
    {
        return new DuplicateRowRetirer(
            $this->db,
            $this->quarantine(),
            new NullLogger(),
        );
    }
}
