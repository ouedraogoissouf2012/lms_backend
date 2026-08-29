<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #541 — ce que les migrations font aux DONNÉES SALES déjà en base.
 *
 * Les tests de schéma vérifient qu'une contrainte existe ; ils ne disent rien de
 * la ligne qu'on a sacrifiée pour pouvoir la poser. Or c'est là que se joue la
 * perte de données : garder l'évaluation vide plutôt que celle qui porte les
 * copies, ou l'inscription abandonnée plutôt que l'active, passe une CI verte
 * tout en effaçant du travail réel.
 *
 * Impossible de fabriquer ces doublons dans les tables déjà contraintes : chaque
 * test annule donc la migration visée (`down()`), sème l'état d'avant, puis la
 * rejoue (`up()`) et observe QUI survit.
 *
 * ⚠️ Sous MySQL, un DDL auto-commite : la transaction de `RefreshDatabase` ne
 * peut plus rien annuler ici. Le nettoyage est donc explicite en `tearDown()`.
 */
final class IntegrityMigrationRepairTest extends TestCase
{
    use RefreshDatabase;

    private const EVALUATIONS_MIGRATION = '2026_08_23_100002_fix_evaluations_klassci_link_unique.php';

    private const ENROLLMENTS_MIGRATION = '2026_08_23_100003_fix_classe_etudiant_unique.php';

    protected function tearDown(): void
    {
        foreach ([
            'evaluation_submissions', 'evaluations', 'classe_etudiant',
            'classes', 'orphan_row_archive', 'users', 'institutions',
        ] as $table) {
            DB::table($table)->delete();
        }

        parent::tearDown();
    }

    public function test_the_evaluation_carrying_the_submissions_is_the_one_kept(): void
    {
        $institutionId = $this->institution();

        $this->migration(self::EVALUATIONS_MIGRATION)->down();

        // L'état qui coûte cher : le brouillon vide est le plus ANCIEN, la vraie
        // évaluation — celle que les étudiants ont composée — est la plus récente.
        $emptyDraft = $this->evaluation($institutionId, 4242, 'Brouillon vide');
        $used = $this->evaluation($institutionId, 4242, 'Celle utilisée');
        $this->submission($used, klassciEtudiantId: 1234);
        $this->submission($used, klassciEtudiantId: 5678);

        $this->migration(self::EVALUATIONS_MIGRATION)->up();

        self::assertNull(
            DB::table('evaluations')->where('id', $used)->value('deleted_at'),
            'L’évaluation portant les copies doit rester vivante.',
        );
        self::assertNotNull(
            DB::table('evaluations')->where('id', $emptyDraft)->value('deleted_at'),
            'Le brouillon vide est celui qu’on retire.',
        );
        self::assertSame(
            1,
            DB::table('orphan_row_archive')
                ->where('source_table', 'evaluations')->where('source_row_id', $emptyDraft)->count(),
            'La ligne retirée doit être récupérable : le soft delete seul ne l’est plus, l’index le refuse.',
        );
    }

    public function test_the_active_enrollment_is_the_one_kept(): void
    {
        $institutionId = $this->institution();
        $classeId = $this->classe($institutionId);
        $userId = $this->user($institutionId);

        $this->migration(self::ENROLLMENTS_MIGRATION)->down();

        // L'ancien index tolérait ces deux lignes : leurs années diffèrent.
        $abandoned = $this->enrollment($classeId, $userId, $institutionId, 'abandonne', 2025);
        $active = $this->enrollment($classeId, $userId, $institutionId, 'actif', 2026);

        $this->migration(self::ENROLLMENTS_MIGRATION)->up();

        self::assertSame(
            [$active],
            DB::table('classe_etudiant')->pluck('id')->map(intval(...))->all(),
            'Garder l’inscription abandonnée sortirait l’étudiant de sa propre classe.',
        );
        self::assertSame(
            1,
            DB::table('orphan_row_archive')
                ->where('source_table', 'classe_etudiant')->where('source_row_id', $abandoned)->count(),
        );
    }

    public function test_both_migrations_can_be_replayed_after_a_partial_failure(): void
    {
        // Sous MySQL chaque DDL auto-commite : une migration interrompue laisse un
        // schéma à moitié posé. Si `up()` n'est pas rejouable, plus aucun
        // `php artisan migrate` ne passe sur le serveur.
        $this->migration(self::EVALUATIONS_MIGRATION)->up();
        $this->migration(self::ENROLLMENTS_MIGRATION)->up();

        self::assertTrue(Schema::hasColumn('evaluations', 'klassci_link_guard'));
        self::assertTrue($this->hasIndex('classe_etudiant', 'classe_etudiant_classe_user_unique'));
    }

    // ----------------------------------------------------------------- helpers

    private function migration(string $filename): object
    {
        /** @var object $migration */
        $migration = require base_path('database/migrations/' . $filename);

        return $migration;
    }

    private function hasIndex(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            /** @var array{name: string} $index */
            if ($index['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    private function institution(): int
    {
        return (int) Institution::factory()->create()->id;
    }

    private function classe(int $institutionId): int
    {
        return (int) Classe::factory()->create(['institution_id' => $institutionId])->id;
    }

    private function user(int $institutionId): int
    {
        return (int) User::factory()->create([
            'institution_id' => $institutionId,
            'role' => 'etudiant',
        ])->id;
    }

    private function evaluation(int $institutionId, int $klassciId, string $titre): int
    {
        return (int) DB::table('evaluations')->insertGetId([
            'institution_id' => $institutionId,
            'klassci_evaluation_id' => $klassciId,
            'klassci_matiere_id' => 1,
            'klassci_classe_id' => 1,
            'titre' => $titre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function submission(int $evaluationId, int $klassciEtudiantId): void
    {
        DB::table('evaluation_submissions')->insert([
            'evaluation_id' => $evaluationId,
            'klassci_etudiant_id' => $klassciEtudiantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enrollment(int $classeId, int $userId, int $institutionId, string $statut, int $annee): int
    {
        return (int) DB::table('classe_etudiant')->insertGetId([
            'classe_id' => $classeId,
            'user_id' => $userId,
            'institution_id' => $institutionId,
            'annee_universitaire_id' => $annee,
            'statut' => $statut,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
