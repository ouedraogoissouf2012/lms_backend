<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #126 — Tests Feature pour `klassci:backfill-enseignant-id`.
 *
 * Couvre l'extraction du backfill `klassci_enseignant_id` de la migration #122
 * vers un command artisan dédié. Le command doit reproduire EXACTEMENT la
 * logique de la migration originale (décodage JSON blob, filtre idempotence,
 * skips silencieux pour blob malformé ou sans `enseignant_id`).
 *
 * Spec: `.claude/specs/backfill-command-artisan/`
 *
 * @see \App\Console\Commands\Klassci\BackfillEnseignantIdCommand
 */
final class BackfillEnseignantIdCommandTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    /**
     * Crée un user et force `klassci_enseignant_id = null` + `klassci_data` selon
     * la spec passée. La factory `UserFactory` par défaut produit un user complet,
     * on overwrite les colonnes ciblées via raw DB::update pour bypass mutators.
     */
    private function userWithBlob(?string $klassciDataJson, ?int $klassciId = 100, string $role = 'enseignant'): User
    {
        $user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => $role,
            'klassci_role'   => $role,
            'klassci_id'     => $klassciId,
        ]);

        DB::table('users')->where('id', $user->id)->update([
            'klassci_enseignant_id' => null,
            'klassci_data'          => $klassciDataJson,
        ]);

        return $user->fresh();
    }

    public function test_backfill_extracts_enseignant_id_from_blob(): void
    {
        $teacher = $this->userWithBlob(
            klassciDataJson: json_encode(['enseignant_id' => 42, 'nom' => 'Doe']),
        );

        $this->artisan('klassci:backfill-enseignant-id')
            ->assertExitCode(0);

        self::assertSame(42, User::find($teacher->id)->klassci_enseignant_id);
    }

    public function test_backfill_skips_non_teachers_without_enseignant_id(): void
    {
        // Un ÉTUDIANT sans enseignant_id reste null : pas de fallback klassci_id
        // pour les non-enseignants (ils ne créent pas d'évaluation — #119).
        $student = $this->userWithBlob(
            klassciDataJson: json_encode(['etudiant_id' => 99, 'nom' => 'Bar']),
            role: 'etudiant',
        );

        $this->artisan('klassci:backfill-enseignant-id')
            ->expectsOutputToContain('skipped 1')
            ->assertExitCode(0);

        self::assertNull(User::find($student->id)->klassci_enseignant_id);
    }

    public function test_backfill_falls_back_to_klassci_id_for_teacher_without_enseignant_id(): void
    {
        // LE cas de l'incident : enseignant dont le blob KLASSCI n'a pas
        // `enseignant_id` (KLASSCI renvoie `enseignant_data` sans id) → fallback
        // sur klassci_id pour débloquer la création d'évaluation (plus de 403).
        $teacher = $this->userWithBlob(
            klassciDataJson: json_encode(['enseignant_data' => ['nb_matieres' => 0], 'nom' => 'Bede']),
            klassciId: 9,
            role: 'enseignant',
        );

        $this->artisan('klassci:backfill-enseignant-id')
            ->expectsOutputToContain('Updated 1')
            ->assertExitCode(0);

        self::assertSame(9, User::find($teacher->id)->klassci_enseignant_id);
    }

    public function test_backfill_is_idempotent(): void
    {
        $teacher = $this->userWithBlob(
            klassciDataJson: json_encode(['enseignant_id' => 42]),
        );

        // Run 1 — backfill effectué
        $this->artisan('klassci:backfill-enseignant-id')->assertExitCode(0);
        self::assertSame(42, User::find($teacher->id)->klassci_enseignant_id);

        // Run 2 — filtre `whereNull('klassci_enseignant_id')` exclut le user,
        // 0 candidat → message "Nothing to backfill".
        $this->artisan('klassci:backfill-enseignant-id')
            ->expectsOutputToContain('Nothing to backfill')
            ->assertExitCode(0);

        // Valeur inchangée.
        self::assertSame(42, User::find($teacher->id)->klassci_enseignant_id);
    }

    public function test_dry_run_does_not_write_to_db(): void
    {
        $teacher = $this->userWithBlob(
            klassciDataJson: json_encode(['enseignant_id' => 42]),
        );

        $this->artisan('klassci:backfill-enseignant-id', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertExitCode(0);

        self::assertNull(
            User::find($teacher->id)->klassci_enseignant_id,
            'klassci_enseignant_id MUST remain null after --dry-run.',
        );
    }

    public function test_handles_malformed_klassci_data_gracefully(): void
    {
        // 3 cas malformés différents (non-enseignants → skip, pas de fallback).
        $u1 = $this->userWithBlob(klassciDataJson: 'invalid json{', klassciId: 100, role: 'etudiant');
        $u2 = $this->userWithBlob(klassciDataJson: null, klassciId: 200, role: 'etudiant');
        $u3 = $this->userWithBlob(klassciDataJson: '[]', klassciId: 300, role: 'etudiant');  // array vide

        $this->artisan('klassci:backfill-enseignant-id')
            ->expectsOutputToContain('skipped 3')
            ->assertExitCode(0);

        self::assertNull(User::find($u1->id)->klassci_enseignant_id);
        self::assertNull(User::find($u2->id)->klassci_enseignant_id);
        self::assertNull(User::find($u3->id)->klassci_enseignant_id);
    }

    public function test_rejects_invalid_chunk_size_below_min(): void
    {
        $this->artisan('klassci:backfill-enseignant-id', ['--chunk' => 0])
            ->expectsOutputToContain('between 1 and 10000')
            ->assertExitCode(1);
    }

    public function test_rejects_invalid_chunk_size_above_max(): void
    {
        $this->artisan('klassci:backfill-enseignant-id', ['--chunk' => 1000000])
            ->expectsOutputToContain('between 1 and 10000')
            ->assertExitCode(1);
    }
}
