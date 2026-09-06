<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seances;

use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciEmploiTempsSeances;
use App\Services\Seances\Sync\TeacherMatieresResolver;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #515 — verrouille l'élimination du N+1 HTTP : `TeacherMatieresResolver`
 * doit résoudre les séances d'un enseignant en UN SEUL appel, quel que soit le
 * nombre de matières.
 *
 * ## Ce que la bascule de source change ici
 *
 * Le résolveur interrogeait `matieres/{id}` en pool pour en lire
 * `data.seances_programmees` — une clé **toujours vide** chez KLASSCI (mesuré le
 * 2026-09-05). La synchronisation ne confirmait donc aucune séance, et
 * `StaleSeanceArchiver` archivait ensuite tout le tenant sous le motif
 * `supprimee_klassci`. La source est maintenant `emploi-temps`.
 *
 * Conséquence directe sur ce fichier : **l'échec PARTIEL n'existe plus.** Un pool
 * de N requêtes pouvait en perdre une et rendre un résultat incomplet ; un appel
 * unique aboutit ou lève. Le test qui verrouillait `failedMatiereIds` est donc
 * remplacé par celui qui verrouille la garantie qui le remplace : une panne se
 * PROPAGE, elle ne devient jamais un résultat vide — sans quoi l'archivage
 * détruirait les séances d'un tenant sur une simple coupure réseau (#582).
 */
#[CoversClass(TeacherMatieresResolver::class)]
final class TeacherMatieresResolverTest extends TestCase
{
    private const TOKEN = 'teacher-token';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_resolves_all_matieres_via_a_single_call(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('getEmploiTemps')
            ->once()
            ->andReturn(['data' => [
                $this->seance(1, matiereId: 10),
                $this->seance(2, matiereId: 20),
            ]]);

        $resolution = $this->resolver($klassci)->resolve([
            ['id' => 10, 'nom' => 'Maths'],
            ['id' => 20, 'nom' => 'Physique'],
        ], self::TOKEN);

        self::assertCount(2, $resolution->resolved);
        self::assertSame([], $resolution->failedMatiereIds);
        self::assertSame(['id' => 10, 'nom' => 'Maths'], $resolution->resolved[10]->matiere);
        self::assertCount(1, $resolution->resolved[10]->seances);
        self::assertSame(1, $resolution->resolved[10]->seances[0]['id']);
    }

    /**
     * Le coût ne dépend plus du nombre de matières : c'est tout l'intérêt de la
     * nouvelle source, et ce qui désarme la rafale d'appels séquentiels.
     */
    public function test_thirty_matieres_still_cost_a_single_call(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('getEmploiTemps')->once()->andReturn(['data' => []]);

        $matieres = [];
        for ($i = 1; $i <= 30; $i++) {
            $matieres[] = ['id' => $i, 'nom' => 'Matiere '.$i];
        }

        self::assertCount(30, $this->resolver($klassci)->resolve($matieres, self::TOKEN)->resolved);
    }

    public function test_matieres_without_an_exploitable_id_are_excluded(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('getEmploiTemps')->once()->andReturn(['data' => []]);

        $resolution = $this->resolver($klassci)->resolve([
            ['id' => 10, 'nom' => 'Maths'],
            ['nom' => 'Sans ID'],
            ['id' => null, 'nom' => 'ID null'],
        ], self::TOKEN);

        self::assertCount(1, $resolution->resolved);
        self::assertArrayHasKey(10, $resolution->resolved);
        // Absence d'ID exploitable : écartée silencieusement, jamais comptée
        // comme échec (comportement inchangé, distinct de failedMatiereIds).
        self::assertSame([], $resolution->failedMatiereIds);
    }

    public function test_empty_matieres_list_short_circuits_without_any_http_call(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldNotReceive('getEmploiTemps');

        $resolution = $this->resolver($klassci)->resolve([], self::TOKEN);

        self::assertSame([], $resolution->resolved);
        self::assertSame([], $resolution->failedMatiereIds);
    }

    /**
     * Une matière SANS séance dans la fenêtre doit tout de même être résolue.
     *
     * Si on l'omettait, l'appelant ne la parcourrait pas, ses séances locales
     * réellement supprimées chez KLASSCI ne seraient jamais détectées comme
     * absentes — et l'archivage légitime n'aurait plus lieu.
     */
    public function test_a_matiere_without_any_seance_is_still_resolved(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('getEmploiTemps')
            ->once()
            ->andReturn(['data' => [$this->seance(1, matiereId: 10)]]);

        $resolution = $this->resolver($klassci)->resolve([
            ['id' => 10, 'nom' => 'Maths'],
            ['id' => 20, 'nom' => 'Physique'],
        ], self::TOKEN);

        self::assertCount(2, $resolution->resolved);
        self::assertSame([], $resolution->resolved[20]->seances);
    }

    /**
     * LA garantie qui protège les données : une panne KLASSCI doit REMONTER.
     *
     * Si elle était avalée en résultat vide, la synchronisation conclurait
     * « plus aucune séance chez KLASSCI » et `StaleSeanceArchiver` désactiverait
     * tout le tenant sur une simple coupure réseau. L'exception, elle, souille
     * le cycle chez l'appelant, ce qui SUSPEND l'archivage (#582).
     */
    public function test_a_klassci_failure_propagates_instead_of_becoming_an_empty_result(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('getEmploiTemps')->andThrow(new RuntimeException('KLASSCI injoignable'));

        $this->expectException(RuntimeException::class);

        $this->resolver($klassci)->resolve([['id' => 10, 'nom' => 'Maths']], self::TOKEN);
    }

    // ───────────────────── Fixtures ─────────────────────

    private function resolver(KlassciProxyService $klassci): TeacherMatieresResolver
    {
        // La source réelle est utilisée volontairement : elle est finale, et la
        // traverser vérifie du même coup que l'adaptation de payload tient.
        return new TeacherMatieresResolver(new KlassciEmploiTempsSeances($klassci));
    }

    private function mockKlassci(): KlassciProxyService&MockInterface
    {
        /** @var KlassciProxyService&MockInterface $klassci */
        $klassci = Mockery::mock(KlassciProxyService::class);

        return $klassci;
    }

    /**
     * Forme RÉELLE d'une entrée `emploi-temps`, relevée le 2026-09-05.
     *
     * @return array<string, mixed>
     */
    private function seance(int $id, int $matiereId): array
    {
        return [
            'id' => $id,
            'matiere' => ['id' => $matiereId],
            'classe' => ['id' => 101, 'nom' => 'B2 COM'],
            'salle' => ['id' => null, 'nom' => 'Salle 1', 'capacite' => null],
            'programmation' => [
                'date_seance' => '2026-06-26',
                'date_cours' => '2026-06-26',
                'heure_debut' => '2026-06-26T08:00:00.000000Z',
                'heure_fin' => '2026-06-26T10:00:00.000000Z',
                'duree_minutes' => 120,
            ],
        ];
    }
}
