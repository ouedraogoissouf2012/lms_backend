<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\User;
use App\Services\Sync\Classes\ClasseStudentsSynchronizer;
use App\Services\TenantManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Issue #541 — l'inscription au pivot `classe_etudiant` doit être idempotente et
 * renseigner l'année universitaire, jusque-là jamais écrite (ce qui rendait
 * l'index unique `(classe_id, user_id, annee_universitaire_id)` inopérant).
 */
#[CoversClass(ClasseStudentsSynchronizer::class)]
final class ClasseStudentsSynchronizerEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private const KLASSCI_STUDENT_ID = 8801;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        app(TenantManager::class)->set($this->institution);
    }

    public function test_syncing_twice_creates_a_single_enrollment(): void
    {
        $classe = $this->classe();

        $this->synchronizer()->sync($classe, [$this->klassciStudent()], 'token');
        $stats = $this->synchronizer()->sync($classe, [$this->klassciStudent()], 'token');

        self::assertSame(1, DB::table('classe_etudiant')->count());
        self::assertSame(0, $stats['enrollments_created'], 'Le second passage ne crée aucune inscription.');
    }

    public function test_enrollment_carries_the_academic_year_of_its_classe(): void
    {
        $classe = $this->classe(anneeUniversitaireId: 2026);

        $this->synchronizer()->sync($classe, [$this->klassciStudent()], 'token');

        self::assertSame(
            2026,
            (int) DB::table('classe_etudiant')->value('annee_universitaire_id'),
            'La colonne d\'année ne doit plus rester morte.',
        );
    }

    public function test_enrollment_is_attached_to_the_resolved_tenant(): void
    {
        $this->synchronizer()->sync($this->classe(), [$this->klassciStudent()], 'token');

        self::assertSame(
            $this->institution->id,
            (int) DB::table('classe_etudiant')->value('institution_id'),
        );
    }

    public function test_a_legacy_row_without_institution_is_repaired_instead_of_duplicated(): void
    {
        $classe = $this->classe();
        $student = User::factory()->create([
            'klassci_id' => self::KLASSCI_STUDENT_ID,
            'role' => 'etudiant',
        ]);

        // Ligne héritée du pré-multi-tenant : rattachement manquant.
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->id,
            'user_id' => $student->id,
            'institution_id' => null,
            'statut' => 'inactif',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->synchronizer()->sync($classe, [$this->klassciStudent()], 'token');

        self::assertSame(1, DB::table('classe_etudiant')->count(), 'Aucun doublon ne doit être créé.');
        $row = DB::table('classe_etudiant')->first();
        self::assertNotNull($row);
        self::assertSame($this->institution->id, (int) $row->institution_id);
        self::assertSame('actif', $row->statut);
    }

    public function test_a_row_owned_by_another_institution_is_left_intact(): void
    {
        $classe = $this->classe();
        $student = User::factory()->create(['klassci_id' => self::KLASSCI_STUDENT_ID, 'role' => 'etudiant']);
        $other = Institution::factory()->create();

        // Anomalie : le pivot est rattaché à une AUTRE institution que sa classe.
        // La garde fail-secure de PR #173 exige qu'on n'y touche pas — la réécrire
        // ferait basculer la ligne dans le tenant courant.
        DB::table('classe_etudiant')->insert([
            'classe_id' => $classe->id,
            'user_id' => $student->id,
            'institution_id' => $other->id,
            'statut' => 'abandonne',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->synchronizer()->sync($classe, [$this->klassciStudent()], 'token');

        $row = DB::table('classe_etudiant')->first();
        self::assertNotNull($row);
        self::assertSame($other->id, (int) $row->institution_id, 'Aucune ré-affectation de tenant.');
        self::assertSame('abandonne', $row->statut, 'La ligne d’une autre institution reste inchangée.');
    }

    public function test_a_race_lost_to_a_concurrent_sync_updates_instead_of_duplicating(): void
    {
        $classe = $this->classe();
        $student = User::factory()->create(['klassci_id' => self::KLASSCI_STUDENT_ID, 'role' => 'etudiant']);

        // Reproduit la course EXACTE : la ligne apparaît entre le `exists()` et
        // l'insertion. Sans le rattrapage, l'unique de #541 ferait remonter une
        // UniqueConstraintViolationException jusqu'au log d'erreur du sync.
        $raced = false;
        // Le motif accepte les DEUX quotings d'identifiant : `"..."` sous SQLite,
        // backticks sous MySQL. Un motif mono-moteur laisserait la jambe MySQL
        // sans aucune couverture de cette course.
        DB::beforeExecuting(function (string $query) use (&$raced, $classe, $student): void {
            if ($raced || preg_match('/insert into [`"]classe_etudiant[`"]/i', $query) !== 1) {
                return;
            }

            $raced = true;
            DB::table('classe_etudiant')->insert([
                'classe_id' => $classe->id,
                'user_id' => $student->id,
                'institution_id' => $this->institution->id,
                'statut' => 'abandonne',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $stats = $this->synchronizer()->sync($classe, [$this->klassciStudent()], 'token');

        self::assertTrue($raced, 'La course doit avoir été déclenchée.');
        self::assertSame(1, DB::table('classe_etudiant')->count(), 'Aucun doublon.');
        self::assertSame('actif', DB::table('classe_etudiant')->value('statut'), 'La ligne gagnante est mise à jour.');
        self::assertSame(0, $stats['enrollments_created'], 'La ligne n’a pas été créée par ce sync.');
    }

    private function classe(?int $anneeUniversitaireId = null): Classe
    {
        return Classe::factory()->create([
            'institution_id' => $this->institution->id,
            'annee_universitaire_id' => $anneeUniversitaireId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function klassciStudent(): array
    {
        return [
            'id' => self::KLASSCI_STUDENT_ID,
            'nom' => 'Traoré',
            'prenom' => 'Awa',
            'email' => 'awa.traore@example.test',
        ];
    }

    private function synchronizer(): ClasseStudentsSynchronizer
    {
        return new ClasseStudentsSynchronizer(
            app(DatabaseManager::class),
            app(TenantManager::class),
            new NullLogger(),
        );
    }
}
