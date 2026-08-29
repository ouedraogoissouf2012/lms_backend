<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\Seances\LocalSeanceLookup;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #476 — Contrat du collaborateur LocalSeanceLookup (pré-chargement anti-N+1).
 *
 * Vérifie que preload() émet un nombre de requêtes borné (2 pour un étudiant,
 * 1 sans étudiant, 0 sur liste vide) et que les résolutions en mémoire
 * (seanceFor/isArchived/isHidden) sont fidèles au comportement des lookups
 * unitaires qu'elles remplacent.
 */
final class LocalSeanceLookupTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
        // Tenant résolu (contexte HTTP) : le scope global institution est actif.
        app(TenantManager::class)->set($this->institution);
        $this->student = User::factory()->for($this->institution)->create(['role' => 'etudiant']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    private function lookup(): LocalSeanceLookup
    {
        return new LocalSeanceLookup;
    }

    private function seance(int $klassciSeanceId, bool $active = true): Seance
    {
        return Seance::factory()->forInstitution($this->institution)->create([
            'klassci_seance_id' => $klassciSeanceId,
            'is_active' => $active,
        ]);
    }

    private function countQueries(callable $run): int
    {
        DB::enableQueryLog();
        $run();
        $n = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $q): bool => str_contains($q, 'seances') || str_contains($q, 'seance_user_hidden'))
            ->count();
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $n;
    }

    public function test_preload_emits_two_queries_for_a_student(): void
    {
        $this->seance(101);
        $lookup = $this->lookup();

        $n = $this->countQueries(fn () => $lookup->preload([101], $this->student));

        self::assertSame(2, $n, 'preload avec étudiant doit émettre 2 requêtes (seances + seance_user_hidden).');
    }

    public function test_preload_emits_one_query_when_student_is_null(): void
    {
        $this->seance(101);
        $lookup = $this->lookup();

        $n = $this->countQueries(fn () => $lookup->preload([101], null));

        self::assertSame(1, $n, 'preload sans étudiant doit émettre 1 seule requête (seances).');
    }

    public function test_preload_emits_no_query_on_empty_id_list(): void
    {
        $lookup = $this->lookup();

        $n = $this->countQueries(fn () => $lookup->preload([], $this->student));

        self::assertSame(0, $n, 'preload sur liste vide ne doit émettre aucune requête.');
    }

    public function test_seance_for_returns_entity_or_null(): void
    {
        $this->seance(101);
        $lookup = $this->lookup();
        $lookup->preload([101], null);

        self::assertSame(101, $lookup->seanceFor(101)?->klassci_seance_id);
        self::assertNull($lookup->seanceFor(999), 'Id absent → null (identique au ->first() nul).');
        self::assertNull($lookup->seanceFor(null));
    }

    public function test_is_archived_true_only_when_local_seance_inactive(): void
    {
        $this->seance(101, active: true);
        $this->seance(102, active: false);
        $lookup = $this->lookup();
        $lookup->preload([101, 102], null);

        self::assertFalse($lookup->isArchived(101), 'Séance active → non archivée.');
        self::assertTrue($lookup->isArchived(102), 'Séance is_active=false → archivée.');
        self::assertFalse($lookup->isArchived(999), 'Id absent → non archivée (visible).');
    }

    public function test_is_hidden_true_only_when_local_id_in_hidden_set(): void
    {
        $this->seance(101);
        $hidden = $this->seance(102);
        SeanceUserHidden::hide($hidden->id, $this->student->id);

        $lookup = $this->lookup();
        $lookup->preload([101, 102], $this->student);

        self::assertFalse($lookup->isHidden(101), 'Séance non masquée → false.');
        self::assertTrue($lookup->isHidden(102), 'Séance masquée pour cet étudiant → true.');
        self::assertFalse($lookup->isHidden(999), 'Id absent → false.');
        // Sans le set masqué (student null), rien n'est masqué.
        $other = $this->lookup();
        $other->preload([101, 102], null);
        self::assertFalse($other->isHidden(102));
    }
}
