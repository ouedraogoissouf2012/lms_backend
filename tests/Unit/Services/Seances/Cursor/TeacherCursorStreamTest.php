<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances\Cursor;

use App\Models\Institution;
use App\Models\User;
use App\Services\Seances\Sync\Cursor\SeanceSyncPosition;
use App\Services\Seances\Sync\Cursor\TeacherCursorStream;
use App\Services\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(TeacherCursorStream::class)]
final class TeacherCursorStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    /**
     * Garde-fou contre un défaut RÉEL et invisible en test : la sélection des
     * enseignants filtrait `whereNotNull('klassci_token')`, colonne SUPPRIMÉE
     * par `2026_04_27_000001_encrypt_klassci_tokens`.
     *
     * SQLite réinterprète un identifiant inconnu entre guillemets doubles comme
     * un LITTÉRAL CHAÎNE : la condition était donc toujours vraie et la suite
     * passait au vert. MySQL, lui, lève `Unknown column` — la sync des séances
     * échouait à chaque exécution en production.
     *
     * Ce test reproduit la détection sans exiger un MySQL en CI : chaque
     * identifiant de la requête réelle doit être une colonne existante.
     */
    public function test_query_only_references_existing_user_columns(): void
    {
        $sql = (new TeacherCursorStream)
            ->query(SeanceSyncPosition::startOfCycle(CarbonImmutable::now()))
            ->toSql();

        preg_match_all('/"([a-zA-Z0-9_]+)"/', $sql, $matches);
        $identifiers = array_values(array_unique($matches[1]));
        $columns = Schema::getColumnListing('users');

        $unknown = array_values(array_filter(
            $identifiers,
            static fn (string $identifier): bool => $identifier !== 'users' && ! in_array($identifier, $columns, true),
        ));

        self::assertSame(
            [],
            $unknown,
            'La requête référence une colonne inexistante — invisible sur SQLite, fatale sur MySQL.',
        );
    }

    /**
     * Invariant central du design : les enseignants d'un même tenant sont
     * CONTIGUS. C'est ce qui permet de détecter la complétude d'un tenant par
     * simple franchissement de frontière, sans connaître toute la population.
     */
    public function test_teachers_are_ordered_by_institution_then_by_id(): void
    {
        [$first, $second] = [Institution::factory()->create(), Institution::factory()->create()];

        $b1 = $this->teacher($second, 'b1');
        $a1 = $this->teacher($first, 'a1');
        $b2 = $this->teacher($second, 'b2');
        $a2 = $this->teacher($first, 'a2');

        $ordered = (new TeacherCursorStream)
            ->after(SeanceSyncPosition::startOfCycle(CarbonImmutable::now()))
            ->pluck('id')
            ->all();

        self::assertSame([$a1->id, $a2->id, $b1->id, $b2->id], $ordered);
    }

    public function test_stream_starts_strictly_after_the_given_position(): void
    {
        $institution = Institution::factory()->create();
        $first = $this->teacher($institution, 'a1');
        $second = $this->teacher($institution, 'a2');

        $remaining = (new TeacherCursorStream)
            ->after(new SeanceSyncPosition($institution->id, $first->id, CarbonImmutable::now()))
            ->pluck('id')
            ->all();

        self::assertSame([$second->id], $remaining);
    }

    /**
     * Le franchissement de tenant doit fonctionner sur le COUPLE : un
     * enseignant d'une institution suivante est atteint même si son `id` est
     * inférieur à celui de la position (les identifiants ne sont pas ordonnés
     * par institution).
     */
    public function test_next_tenant_is_reached_even_when_its_teacher_id_is_lower(): void
    {
        [$first, $second] = [Institution::factory()->create(), Institution::factory()->create()];
        $lowIdInSecondTenant = $this->teacher($second, 'b1');
        $highIdInFirstTenant = $this->teacher($first, 'a1');

        $remaining = (new TeacherCursorStream)
            ->after(new SeanceSyncPosition($first->id, $highIdInFirstTenant->id, CarbonImmutable::now()))
            ->pluck('id')
            ->all();

        self::assertSame([$lowIdInSecondTenant->id], $remaining);
        self::assertLessThan($highIdInFirstTenant->id, $lowIdInSecondTenant->id);
    }

    public function test_excludes_users_that_cannot_be_synced(): void
    {
        $institution = Institution::factory()->create();
        $eligible = $this->teacher($institution, 'ok');

        User::factory()->for($institution)->create(['role' => 'etudiant', 'klassci_token' => 'x']);
        User::factory()->for($institution)->create(['role' => 'enseignant', 'klassci_token' => null]);
        // institution_id est la clé de tenant : sans elle, aucune synchronisation
        // isolée n'est possible (#473).
        User::factory()->create(['role' => 'enseignant', 'klassci_token' => 'y', 'institution_id' => null]);

        $ids = (new TeacherCursorStream)
            ->after(SeanceSyncPosition::startOfCycle(CarbonImmutable::now()))
            ->pluck('id')
            ->all();

        self::assertSame([$eligible->id], $ids);
    }

    /**
     * R2 — le flux est paresseux : une passe peut s'arrêter au budget après
     * quelques enseignants sans que la population entière ait été matérialisée.
     */
    public function test_stream_is_lazy_so_a_pass_can_stop_early(): void
    {
        $institution = Institution::factory()->create();
        foreach (range(1, 5) as $index) {
            $this->teacher($institution, "t{$index}");
        }

        $stream = (new TeacherCursorStream)->after(SeanceSyncPosition::startOfCycle(CarbonImmutable::now()));

        self::assertInstanceOf(LazyCollection::class, $stream);
        self::assertCount(2, $stream->take(2)->all());
    }

    public function test_has_more_after_reports_the_end_of_the_population(): void
    {
        $institution = Institution::factory()->create();
        $only = $this->teacher($institution, 'a1');
        $stream = new TeacherCursorStream;

        self::assertTrue($stream->hasMoreAfter(SeanceSyncPosition::startOfCycle(CarbonImmutable::now())));
        self::assertFalse($stream->hasMoreAfter(
            new SeanceSyncPosition($institution->id, $only->id, CarbonImmutable::now())
        ));
    }

    private function teacher(Institution $institution, string $token): User
    {
        return User::factory()->for($institution)->create([
            'role' => 'enseignant',
            'klassci_token' => $token,
        ]);
    }
}
