<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Search\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Issue #505 — une source KLASSCI en panne ne doit plus être confondue avec
 * « 0 résultat », et un résultat dégradé ne doit pas être servi pendant 5 minutes
 * depuis le cache.
 *
 * La panne est simulée à la couture d'injection réelle ({@see KlassciProxyService}),
 * pas sur un double du service testé : le chemin de production est donc exercé en
 * entier, filtrage compris.
 */
final class SearchDegradationTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'math';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $institution = Institution::factory()->create();
        $this->staff = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'coordinateur',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_healthy_search_reports_no_failed_source(): void
    {
        $this->fakeKlassci(classes: $this->klassciClasses(), matieres: $this->klassciMatieres());

        $payload = $this->search();

        self::assertSame([], $payload['sources_failed']);
        self::assertCount(1, $payload['results']['classes']);
        self::assertCount(1, $payload['results']['matieres']);
    }

    public function test_classes_outage_is_named_and_the_other_buckets_survive(): void
    {
        $this->fakeKlassci(classes: new RuntimeException('KLASSCI down'), matieres: $this->klassciMatieres());

        $payload = $this->search();

        self::assertSame(['classes'], $payload['sources_failed']);
        self::assertSame([], $payload['results']['classes']);
        self::assertCount(1, $payload['results']['matieres'], 'Une source en panne ne doit pas emporter les autres.');
    }

    public function test_matieres_outage_is_named(): void
    {
        $this->fakeKlassci(classes: $this->klassciClasses(), matieres: new RuntimeException('KLASSCI down'));

        self::assertSame(['matieres'], $this->search()['sources_failed']);
    }

    public function test_both_outages_are_named(): void
    {
        $this->fakeKlassci(classes: new RuntimeException('down'), matieres: new RuntimeException('down'));

        self::assertSame(['classes', 'matieres'], $this->search()['sources_failed']);
    }

    public function test_the_klassci_envelope_is_unwrapped_instead_of_being_taken_for_an_outage(): void
    {
        // Forme RÉELLE du proxy — celle que lisent EvaluationCreationService:161,
        // EvaluationEnrichmentService:64 et StudentGradesAggregator:59. Le code
        // d'origine parcourait l'enveloppe elle-même et ne trouvait jamais rien.
        $this->fakeKlassci(
            classes: ['success' => true, 'data' => $this->klassciClasses()],
            matieres: ['success' => true, 'data' => $this->klassciMatieres()],
        );

        $payload = $this->search();

        self::assertSame([], $payload['sources_failed'], 'Une réponse SAINE ne doit pas être signalée en panne.');
        self::assertCount(1, $payload['results']['classes']);
        self::assertSame('Mathématiques L1', $payload['results']['classes'][0]['title']);
    }

    public function test_a_response_without_any_record_list_degrades_the_source(): void
    {
        // Ni liste nue, ni enveloppe à `data` : impossible d'en tirer des
        // enregistrements. Le parcourir quand même produirait un « 0 résultat »
        // indiscernable d'une recherche infructueuse — l'ambiguïté que #505 lève.
        $this->fakeKlassci(
            classes: ['message' => 'Service momentanément indisponible'],
            matieres: $this->klassciMatieres(),
        );

        $payload = $this->search();

        self::assertSame(['classes'], $payload['sources_failed']);
        self::assertCount(1, $payload['results']['matieres'], 'Les autres sources restent intactes.');
    }

    // ------------------------------------------------------------------- cache

    public function test_a_degraded_result_is_never_served_as_if_it_were_complete(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('getClasses')->once()->andThrow(new RuntimeException('down'));
        $klassci->shouldReceive('getMatieres')->once()->andReturn($this->klassciMatieres());

        self::assertSame(['classes'], $this->search()['sources_failed'], 'Premier appel : KLASSCI est à terre.');

        // Servi depuis le cache — mais le drapeau VOYAGE avec les données : à
        // aucun moment le client ne reçoit un résultat amputé présenté comme
        // complet, ce que faisait le cache de 5 minutes d'origine.
        self::assertSame(['classes'], $this->search()['sources_failed']);
    }

    public function test_a_degraded_result_expires_quickly_so_recovery_is_visible(): void
    {
        // #505/#574 — l'expiration est simulée via le time-travel Carbon
        // ($this->travel). Le store redis expire ses clés sur l'horloge RÉELLE,
        // pas sur Carbon : sous la jambe CI redis, l'entrée dégradée ne « périme »
        // pas et le test échoue à tort. Le comportement prod reste correct (TTL
        // réel de 30 s). On restreint donc ce cas aux stores pilotés par Carbon.
        if (app('cache')->store()->getStore() instanceof \Illuminate\Cache\RedisStore) {
            self::markTestSkipped('TTL redis suit l\'horloge réelle, pas le time-travel Carbon.');
        }

        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('getClasses')->once()->andThrow(new RuntimeException('down'));
        $klassci->shouldReceive('getMatieres')->twice()->andReturn($this->klassciMatieres());

        self::assertSame(['classes'], $this->search()['sources_failed']);

        // KLASSCI se rétablit. L'entrée dégradée ne doit pas survivre cinq
        // minutes : trente secondes plus tard, la recherche est complète.
        $klassci->shouldReceive('getClasses')->once()->andReturn($this->klassciClasses());
        $this->travel(31)->seconds();

        $recovered = $this->search();

        self::assertSame([], $recovered['sources_failed']);
        self::assertCount(1, $recovered['results']['classes']);
    }

    public function test_a_complete_result_keeps_the_five_minute_cache(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldReceive('getClasses')->once()->andReturn($this->klassciClasses());
        $klassci->shouldReceive('getMatieres')->once()->andReturn($this->klassciMatieres());

        $this->search();

        // Bien au-delà du TTL dégradé : un résultat SAIN, lui, reste mémorisé.
        $this->travel(31)->seconds();
        $second = $this->search();

        self::assertSame([], $second['sources_failed']);
        self::assertCount(1, $second['results']['classes'], 'Le second appel doit être servi par le cache.');
    }

    // --------------------------------------------------------------- périmètre

    public function test_a_student_never_reaches_the_klassci_sources(): void
    {
        $klassci = $this->mockKlassci();
        $klassci->shouldNotReceive('getClasses');
        $klassci->shouldNotReceive('getMatieres');

        $student = User::factory()->create([
            'institution_id' => $this->staff->institution_id,
            'role' => 'etudiant',
        ]);

        $payload = app(GlobalSearchService::class)->search(self::QUERY, $student);

        self::assertSame([], $payload['sources_failed']);
    }

    public function test_the_endpoint_exposes_the_failed_sources(): void
    {
        $this->fakeKlassci(classes: new RuntimeException('down'), matieres: new RuntimeException('down'));

        Sanctum::actingAs($this->staff);

        $this->getJson('/api/search?query=' . self::QUERY)
            ->assertOk()
            ->assertJsonPath('sources_failed', ['classes', 'matieres']);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @return array{
     *     results: array<string, array<int, array<string, mixed>>>,
     *     total: int,
     *     categories: array<string, int>,
     *     sources_failed: list<string>
     * }
     */
    private function search(): array
    {
        return app(GlobalSearchService::class)->search(self::QUERY, $this->staff);
    }

    /**
     * @param  array<mixed>|Throwable  $classes
     * @param  array<mixed>|Throwable  $matieres
     */
    private function fakeKlassci(array|Throwable $classes, array|Throwable $matieres): MockInterface
    {
        $klassci = $this->mockKlassci();

        // Chaque source est attendue EXACTEMENT une fois, panne comprise : une
        // borne posée d'un seul côté laisserait passer une régression qui
        // multiplierait les appels à l'autre.
        $classes instanceof Throwable
            ? $klassci->shouldReceive('getClasses')->once()->andThrow($classes)
            : $klassci->shouldReceive('getClasses')->once()->andReturn($classes);

        $matieres instanceof Throwable
            ? $klassci->shouldReceive('getMatieres')->once()->andThrow($matieres)
            : $klassci->shouldReceive('getMatieres')->once()->andReturn($matieres);

        return $klassci;
    }

    private function mockKlassci(): MockInterface
    {
        $klassci = Mockery::mock(KlassciProxyService::class);
        $this->app?->instance(KlassciProxyService::class, $klassci);

        return $klassci;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function klassciClasses(): array
    {
        return [[
            'id' => 12,
            'name' => 'Mathématiques L1',
            'filiere' => ['name' => 'Informatique'],
            'niveau' => ['name' => 'Licence 1'],
        ]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function klassciMatieres(): array
    {
        return [[
            'id' => 34,
            'nom' => 'Analyse mathématique',
            'code' => 'MAT101',
        ]];
    }
}
