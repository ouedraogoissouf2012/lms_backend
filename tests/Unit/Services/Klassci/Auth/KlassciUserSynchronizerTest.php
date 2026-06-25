<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use App\Services\KlassciProxyService;
use App\Services\MatiereSyncService;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Issue #120 — Tests unit pour KlassciUserSynchronizer.
 *
 * Couvre les invariants critiques :
 * - CREATE : init `role` + `klassci_enseignant_id` write-once + password uniqid
 * - UPDATE : préservation `role` LMS (CRITICAL-05 garde)
 * - UPDATE : préservation `klassci_enseignant_id` (issue #119 garde)
 * - Recherche par `(klassci_id, institution_id)` puis fallback email
 * - Sync classes pour étudiants uniquement
 *
 * @see app/Services/Klassci/Auth/KlassciUserSynchronizer.php
 * @see .claude/specs/auth-controller-refactor/requirements.md REQ-3
 */
#[CoversClass(KlassciUserSynchronizer::class)]
final class KlassciUserSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private KlassciProxyService $klassciService;

    private MatiereSyncService $matiereSync;

    private LoggerInterface $logger;

    private KlassciUserSynchronizer $synchronizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'test-inst']);

        $this->klassciService = Mockery::mock(KlassciProxyService::class);
        // Spy : la sync matières (enseignant/coordinateur, #258) est interceptée sans
        // exécution — ces tests couvrent la logique user, pas la sync matières.
        $this->matiereSync = Mockery::spy(MatiereSyncService::class);
        $this->logger = Mockery::spy(LoggerInterface::class);

        $this->synchronizer = new KlassciUserSynchronizer(
            DB::connection(),
            Hash::driver(),
            $this->klassciService,
            $this->matiereSync,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_creates_new_user_when_klassci_id_unknown(): void
    {
        $klassciUser = [
            'id'    => 99,
            'email' => 'newuser@test.com',
            'nom'   => 'Nouveau',
            'role'  => 'enseignant',
        ];

        $user = $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        self::assertNotNull($user->id);
        self::assertSame(99, $user->klassci_id);
        self::assertSame('newuser@test.com', $user->email);
        self::assertSame('enseignant', $user->role);
        self::assertSame('enseignant', $user->klassci_role);
        self::assertSame($this->institution->id, $user->institution_id);
    }

    public function test_initializes_klassci_enseignant_id_write_once_on_creation(): void
    {
        $klassciUser = [
            'id'             => 100,
            'email'          => 'enseignant@test.com',
            'role'           => 'enseignant',
            'enseignant_id'  => 42,
        ];

        $user = $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        self::assertSame(42, $user->klassci_enseignant_id);
    }

    public function test_creates_user_with_uniqid_password(): void
    {
        $klassciUser = ['id' => 101, 'email' => 'pwd@test.com', 'role' => 'etudiant'];

        $user = $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        // Password non-null (NOT NULL constraint DB satisfaite)
        self::assertNotNull($user->password);
        self::assertNotEmpty($user->password);
    }

    public function test_updates_existing_user_by_klassci_id(): void
    {
        $existing = User::factory()->for($this->institution)->create([
            'klassci_id' => 200,
            'email'      => 'old@test.com',
            'name'       => 'Old Name',
            'role'       => 'enseignant',
        ]);

        $klassciUser = [
            'id'    => 200,
            'email' => 'newemail@test.com',
            'nom'   => 'New Name',
            'role'  => 'enseignant',
        ];

        $user = $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        self::assertSame($existing->id, $user->id);
        self::assertSame('newemail@test.com', $user->email);
        self::assertSame('New Name', $user->name);
    }

    public function test_falls_back_to_email_lookup_when_klassci_id_changes(): void
    {
        // User existant avec ancien klassci_id
        $existing = User::factory()->for($this->institution)->create([
            'klassci_id' => 300,
            'email'      => 'stable@test.com',
            'role'       => 'enseignant',
        ]);

        // KLASSCI envoie nouveau klassci_id mais même email
        $klassciUser = [
            'id'    => 301,
            'email' => 'stable@test.com',
            'role'  => 'enseignant',
        ];

        $user = $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        self::assertSame($existing->id, $user->id);
        self::assertSame(301, $user->klassci_id);  // klassci_id mis à jour
    }

    /**
     * 🔑 GARDE CRITICAL-05 (PR #118).
     *
     * Pour un user existant, `role` LMS ne doit JAMAIS être écrasé par KLASSCI.
     * Seul `klassci_role` capture la valeur KLASSCI.
     */
    public function test_does_not_overwrite_role_lms_on_update(): void
    {
        $existing = User::factory()->for($this->institution)->create([
            'klassci_id'   => 400,
            'email'        => 'admin@test.com',
            'role'         => 'admin',          // ← Role LMS sensible
            'klassci_role' => 'admin',
        ]);

        // KLASSCI essaie de pousser role=supradmin (compromis)
        $klassciUser = [
            'id'    => 400,
            'email' => 'admin@test.com',
            'role'  => 'supradmin',  // ← Escalade tentée
        ];

        $user = $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        self::assertSame('admin', $user->role, 'role LMS doit rester admin');
        self::assertSame('supradmin', $user->klassci_role, 'klassci_role capture la tentative');
    }

    /**
     * 🔑 GARDE ISSUE #119 (PR #122).
     *
     * `klassci_enseignant_id` est write-once. Une fois initialisé, KLASSCI ne
     * peut plus l'écraser même au login. Sinon IDOR via évaluations.
     */
    public function test_does_not_overwrite_klassci_enseignant_id_on_update(): void
    {
        $existing = User::factory()->for($this->institution)->create([
            'klassci_id'            => 500,
            'email'                 => 'teacher@test.com',
            'role'                  => 'enseignant',
            'klassci_enseignant_id' => 42,  // ← Source d'autorité figée
        ]);

        // KLASSCI essaie de pousser enseignant_id=666
        $klassciUser = [
            'id'             => 500,
            'email'          => 'teacher@test.com',
            'role'           => 'enseignant',
            'enseignant_id'  => 666,
        ];

        $user = $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        self::assertSame(42, $user->klassci_enseignant_id, 'klassci_enseignant_id doit rester 42 (write-once)');
    }

    public function test_updates_klassci_role_on_update(): void
    {
        $existing = User::factory()->for($this->institution)->create([
            'klassci_id'   => 600,
            'email'        => 'changing@test.com',
            'role'         => 'etudiant',
            'klassci_role' => 'etudiant',
        ]);

        $klassciUser = ['id' => 600, 'email' => 'changing@test.com', 'role' => 'enseignant'];

        $user = $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        self::assertSame('enseignant', $user->klassci_role);
        self::assertSame('etudiant', $user->role);  // role LMS inchangé (CRITICAL-05)
    }

    public function test_triggers_sync_classes_only_for_students(): void
    {
        $this->klassciService->shouldReceive('requestWithUserToken')
            ->with('tok', 'me/dashboard', 'GET')
            ->andReturn(['data' => ['classe' => ['id' => 1, 'name' => 'B2', 'libelle' => 'B2 COM']]])
            ->once();

        $klassciUser = ['id' => 700, 'email' => 'student@test.com', 'role' => 'etudiant'];

        $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        // Vérifie qu'une UserClass a été créée
        self::assertDatabaseHas('user_classes', [
            'klassci_classe_id' => 1,
            'classe_nom'        => 'B2',
        ]);
    }

    public function test_does_not_trigger_sync_classes_for_non_students(): void
    {
        $this->klassciService->shouldNotReceive('requestWithUserToken');

        $klassciUser = ['id' => 800, 'email' => 'admin@test.com', 'role' => 'admin'];

        $user = $this->synchronizer->sync($klassciUser, 'tok', 'https://t.test', $this->institution);

        // Vérification explicite : aucune classe créée pour ce user
        self::assertDatabaseMissing('user_classes', ['user_id' => $user->id]);
        self::assertSame('admin', $user->role);
    }
}
