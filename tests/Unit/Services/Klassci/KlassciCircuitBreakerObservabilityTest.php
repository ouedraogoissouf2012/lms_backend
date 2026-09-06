<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Services\Klassci\KlassciCircuitBreaker;
use App\Services\Klassci\KlassciTargetResolver;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

/**
 * Une ouverture de disjoncteur ne doit plus être invisible (#744).
 *
 * ## Ce que l'absence de trace coûtait
 *
 * `KlassciCircuitBreaker` n'écrivait AUCUN log : `grep -n "logger" ` sur la classe
 * ne renvoyait rien. Or son ouverture rend **503 à tous les utilisateurs pendant
 * 30 secondes**, sur trois échecs en une minute — y compris quand KLASSCI répond
 * parfaitement et que les échecs venaient d'un filtrage réseau transitoire.
 *
 * Conséquence concrète : à la question « à quels horodatages avez-vous été
 * bloqués ? », que l'hébergeur pose pour instruire une mise en liste blanche,
 * personne ne pouvait répondre. Un incident de 30 s ne laissait pas la moindre
 * trace.
 *
 * Ce fichier verrouille le minimum opposable : l'ouverture et la fermeture sont
 * journalisées, avec de quoi les dater et les compter.
 */
#[CoversClass(KlassciCircuitBreaker::class)]
final class KlassciCircuitBreakerObservabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('array')->flush();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * L'ouverture — le moment où tous les utilisateurs commencent à recevoir 503
     * — doit produire une entrée de niveau `error`.
     */
    public function test_opening_the_circuit_is_logged_as_an_error(): void
    {
        $journal = new CircuitLogSpy;
        $breaker = $this->breaker($journal);

        $this->pushToOpen($breaker);

        $ouvertures = $journal->matching('circuit breaker OPEN');
        self::assertCount(1, $ouvertures, 'Une ouverture doit laisser exactement une trace.');
        self::assertSame('error', $ouvertures[0]['level'], 'Un 503 imposé à tous n\'est pas un simple avertissement.');
    }

    /**
     * La trace doit porter de quoi instruire un dossier : combien d'échecs l'ont
     * déclenchée, et pour combien de temps le service est coupé.
     */
    public function test_the_opening_log_carries_what_an_incident_report_needs(): void
    {
        $journal = new CircuitLogSpy;
        $this->pushToOpen($this->breaker($journal));

        $contexte = $journal->matching('circuit breaker OPEN')[0]['context'];

        foreach (['failures', 'cooldown_seconds'] as $cle) {
            self::assertArrayHasKey($cle, $contexte, "Sans `{$cle}`, l'incident n'est pas exploitable.");
        }
        self::assertSame(3, $contexte['failures']);
        self::assertGreaterThan(0, $contexte['cooldown_seconds']);
    }

    /**
     * Les échecs qui n'ouvrent PAS le disjoncteur ne doivent pas produire de
     * bruit au niveau `error` : l'issue #256 documente 239 Mo de logs nés
     * exactement de cette confusion.
     */
    public function test_a_failure_below_the_threshold_does_not_log_an_error(): void
    {
        $journal = new CircuitLogSpy;
        $breaker = $this->breaker($journal);

        $breaker->reportFailure();
        $breaker->reportFailure();

        self::assertSame([], $journal->matching('circuit breaker OPEN'), 'Le seuil n\'est pas atteint : rien à signaler.');
        self::assertSame([], $journal->ofLevel('error'), 'Deux échecs isolés ne sont pas un incident.');
    }

    /**
     * Le retour à la normale se date aussi : sans lui, on connaît le début d'un
     * incident mais jamais sa fin.
     */
    public function test_recovery_is_logged_when_the_circuit_was_open(): void
    {
        $journal = new CircuitLogSpy;
        $breaker = $this->breaker($journal);

        $this->pushToOpen($breaker);
        $breaker->reportSuccess();

        self::assertCount(1, $journal->matching('circuit breaker CLOSED'));
    }

    /**
     * Mais un succès nominal — disjoncteur déjà fermé — ne doit rien écrire.
     * Sinon chaque appel réussi produirait une ligne, et le signal se noierait.
     */
    public function test_a_nominal_success_stays_silent(): void
    {
        $journal = new CircuitLogSpy;

        $this->breaker($journal)->reportSuccess();

        self::assertSame([], $journal->entries, 'Un succès ordinaire n\'est pas un événement.');
    }

    // ───────────────────── Fixtures ─────────────────────

    /** Trois échecs = le seuil par défaut (`circuit_breaker_failures`). */
    private function pushToOpen(KlassciCircuitBreaker $breaker): void
    {
        $breaker->reportFailure();
        $breaker->reportFailure();
        $breaker->reportFailure();
    }

    private function breaker(CircuitLogSpy $journal): KlassciCircuitBreaker
    {
        // Mock de l'interface plutôt qu'un double local : `FakeTargetResolver`
        // vit déjà dans KlassciCircuitBreakerTest, et le redéclarer ici ferait
        // planter toute exécution chargeant les deux fichiers.
        $target = Mockery::mock(KlassciTargetResolver::class);
        $target->shouldReceive('baseUrl')->andReturn('https://klassci.test');

        return new KlassciCircuitBreaker(Cache::store('array'), $target, $journal);
    }
}

/**
 * Journal d'essai : conserve chaque entrée pour pouvoir l'interroger.
 */
final class CircuitLogSpy extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $entries = [];

    /**
     * @param  mixed  $level
     * @param  string|Stringable  $message
     * @param  array<string, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->entries[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function matching(string $fragment): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $e): bool => str_contains($e['message'], $fragment),
        ));
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function ofLevel(string $level): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $e): bool => $e['level'] === $level,
        ));
    }
}
