<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * D72, E2a : la migration qui rend les copies à leur auteur par `student_id`.
 *
 * - Des doublons `(evaluation_id, student_id, attempt)` déjà en base font
 *   échouer la migration avec leur liste, au lieu d'un 1062 opaque.
 * - `down()` est réversible tant qu'aucune copie n'est sans identité KLASSCI,
 *   et refuse sinon.
 *
 * Joué sous SQLite en local. La preuve MySQL est celle du premier run CI de la
 * PR (déclarée en attente, D150).
 */
final class SubmissionsOwnedByStudentMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const UNIQUE = 'eval_sub_student_unique';

    public function test_des_doublons_existants_font_echouer_la_migration_avec_leur_liste(): void
    {
        Schema::table('evaluation_submissions', fn (Blueprint $table) => $table->dropUnique(self::UNIQUE));
        [$evaluation, $eleve] = $this->evaluationEtEleve();
        $this->copie($evaluation, $eleve, klassci: 1);
        $this->copie($evaluation, $eleve, klassci: 2);

        try {
            $this->migration()->up();
            self::fail('La migration aurait dû échouer sur les doublons.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString("évaluation {$evaluation->getKey()}, élève {$eleve->getKey()}, tentative 1 (2 fois)", $e->getMessage());
        }
        // Rien n'a été touché : l'unique n'est pas recréé.
        self::assertFalse(Schema::hasIndex('evaluation_submissions', self::UNIQUE));
    }

    public function test_down_puis_up_est_reversible_sans_copie_locale(): void
    {
        [$evaluation, $eleve] = $this->evaluationEtEleve();
        $this->copie($evaluation, $eleve, klassci: 7);

        $this->migration()->down();
        self::assertFalse(Schema::hasIndex('evaluation_submissions', self::UNIQUE));

        $this->migration()->up();
        self::assertTrue(Schema::hasIndex('evaluation_submissions', self::UNIQUE));
        self::assertSame(1, DB::table('evaluation_submissions')->count());
    }

    public function test_l_ancien_unique_survit_a_la_migration(): void
    {
        // `change()` reconstruit la table sous SQLite : l'ancien unique doit
        // en sortir intact, il protège encore les copies KLASSCI.
        self::assertTrue(Schema::hasIndex('evaluation_submissions', 'eval_sub_unique'));

        $this->migration()->down();
        $this->migration()->up();

        self::assertTrue(Schema::hasIndex('evaluation_submissions', 'eval_sub_unique'));
        self::assertTrue(Schema::hasIndex('evaluation_submissions', self::UNIQUE));
    }

    public function test_down_refuse_une_copie_sans_identite_klassci(): void
    {
        [$evaluation, $eleve] = $this->evaluationEtEleve();
        $this->copie($evaluation, $eleve, klassci: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("1 copie(s) n'ont pas d'identité KLASSCI");

        $this->migration()->down();
    }

    public function test_l_unique_refuse_une_seconde_copie_de_meme_tentative_pour_le_meme_eleve(): void
    {
        [$evaluation, $eleve] = $this->evaluationEtEleve();
        $this->copie($evaluation, $eleve, klassci: null);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->copie($evaluation, $eleve, klassci: null);
    }

    // ───────────────────────── harnais

    /**
     * @return array{Evaluation, User}
     */
    private function evaluationEtEleve(): array
    {
        $evaluation = Evaluation::factory()->create();

        return [$evaluation, User::factory()->create(['institution_id' => $evaluation->institution_id, 'role' => 'etudiant'])];
    }

    private function copie(Evaluation $evaluation, User $eleve, ?int $klassci): void
    {
        DB::table('evaluation_submissions')->insert([
            'evaluation_id' => $evaluation->getKey(),
            'student_id' => $eleve->getKey(),
            'klassci_etudiant_id' => $klassci,
            'attempt' => 1,
            'status' => 'soumis',
            'institution_id' => $evaluation->institution_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migration(): Migration
    {
        /** @var Migration */
        return require base_path('database/migrations/2026_11_12_200000_submissions_owned_by_student.php');
    }
}
