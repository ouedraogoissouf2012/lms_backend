<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Services\Klassci\KlassciCircuitBreaker;
use App\Services\Klassci\KlassciTargetResolver;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Issue #578 — Cloisonnement du circuit breaker KLASSCI par cible réseau.
 *
 * En multi-tenant, chaque institution résout sa propre `klassci_api_url`
 * ({@see \App\Services\Klassci\KlassciConfigResolver::baseUrl()}). Un état de
 * disjoncteur GLOBAL (constantes littérales) provoquait deux pannes symétriques :
 *
 *  - faux positif : 3 échecs sur A ouvraient le disjoncteur de TOUTES les écoles ;
 *  - faux négatif : un succès de B effaçait le compteur d'échecs de A.
 *
 * Ces tests prouvent que l'état est désormais partitionné par empreinte d'URL :
 * cibles distinctes = états indépendants, même URL = état partagé, pas d'URL =
 * repli `default`.
 *
 * @see .claude/specs/578-circuit-breaker-per-tenant/design.md
 */
#[CoversClass(KlassciCircuitBreaker::class)]
final class KlassciCircuitBreakerTest extends TestCase
{
    private const URL_A = 'https://ecole-a.klassci.test';

    private const URL_B = 'https://ecole-b.klassci.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.klassci.circuit_breaker_enabled' => true,
            'services.klassci.circuit_breaker_failures' => 3,
            'services.klassci.circuit_breaker_cooldown' => 30,
            'services.klassci.circuit_breaker_window' => 60,
        ]);

        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        Cache::store('array')->flush();
        parent::tearDown();
    }

    /**
     * R1 — Faux positif éliminé : 3 échecs sur la cible A ouvrent A seulement ;
     * une cible B distincte reste fermée.
     */
    public function test_failures_on_A_do_not_open_B(): void
    {
        $cache = $this->cache();
        $breakerA = $this->breaker($cache, self::URL_A);
        $breakerB = $this->breaker($cache, self::URL_B);

        $this->trip($breakerA);

        self::assertTrue($breakerA->isOpen(), 'La cible A doit être ouverte après 3 échecs.');
        self::assertFalse($breakerB->isOpen(), 'La cible B distincte ne doit PAS être affectée par les pannes de A.');
    }

    /**
     * R2 — Faux négatif éliminé : un succès sur B n'efface pas le compteur
     * d'échecs de A. On accumule 2 échecs sur A (< seuil), un succès sur B, puis
     * 1 échec sur A : si le compteur de A avait été effacé par B, A ne serait pas
     * ouvert (1 < 3). Il l'est donc le compteur a survécu.
     */
    public function test_success_on_B_preserves_A_failure_counter(): void
    {
        $cache = $this->cache();
        $breakerA = $this->breaker($cache, self::URL_A);
        $breakerB = $this->breaker($cache, self::URL_B);

        $breakerA->reportFailure();
        $breakerA->reportFailure();

        $breakerB->reportSuccess();

        $breakerA->reportFailure();

        self::assertTrue(
            $breakerA->isOpen(),
            'Le 3e échec de A doit ouvrir A : le succès de B ne doit pas avoir remis le compteur de A à zéro.',
        );
        self::assertFalse($breakerB->isOpen(), 'B n\'a jamais échoué et reste fermé.');
    }

    /**
     * R3 — Deux institutions co-hébergées (même URL de base résolue) partagent un
     * seul disjoncteur : une panne du serveur commun les protège toutes les deux.
     */
    public function test_same_base_url_shares_breaker(): void
    {
        $cache = $this->cache();
        $sharedUrl = 'https://mutualise.klassci.test';
        $breakerFirst = $this->breaker($cache, $sharedUrl);
        $breakerSecond = $this->breaker($cache, $sharedUrl);

        $this->trip($breakerFirst);

        self::assertTrue(
            $breakerSecond->isOpen(),
            'Deux résolveurs pointant la même URL doivent partager l\'état du disjoncteur.',
        );
    }

    /**
     * R4 — Sans URL résolue, le breaker utilise la partition de repli `default`,
     * isolée des cibles réelles (dans les deux sens).
     */
    public function test_null_base_url_uses_isolated_default_partition(): void
    {
        $cache = $this->cache();
        $breakerDefault = $this->breaker($cache, null);
        $breakerReal = $this->breaker($cache, self::URL_A);

        $this->trip($breakerDefault);

        self::assertTrue($breakerDefault->isOpen(), 'La partition default doit s\'ouvrir sur ses propres échecs.');
        self::assertFalse($breakerReal->isOpen(), 'Une cible réelle ne doit pas hériter de l\'ouverture de default.');
    }

    /**
     * R4 (symétrique) — une chaîne vide est traitée comme absence de cible.
     */
    public function test_empty_base_url_falls_back_to_default(): void
    {
        $cache = $this->cache();
        $breakerEmpty = $this->breaker($cache, '   ');
        $breakerDefault = $this->breaker($cache, null);

        $this->trip($breakerEmpty);

        self::assertTrue(
            $breakerDefault->isOpen(),
            'Une URL vide/espaces doit retomber sur la même partition default qu\'une URL nulle.',
        );
    }

    /**
     * R6.1 — L'interrupteur `circuit_breaker_enabled=false` neutralise l'ouverture,
     * par partition comme avant.
     */
    public function test_disabled_flag_prevents_open(): void
    {
        config(['services.klassci.circuit_breaker_enabled' => false]);

        $breaker = $this->breaker($this->cache(), self::URL_A);

        for ($i = 0; $i < 10; $i++) {
            $breaker->reportFailure();
        }

        self::assertFalse($breaker->isOpen(), 'Disjoncteur désactivé : aucune ouverture même après 10 échecs.');
    }

    private function cache(): CacheRepository
    {
        return Cache::store('array');
    }

    private function breaker(CacheRepository $cache, ?string $baseUrl): KlassciCircuitBreaker
    {
        return new KlassciCircuitBreaker($cache, new FakeTargetResolver($baseUrl));
    }

    /** Fait franchir le seuil d'ouverture (3 échecs par défaut). */
    private function trip(KlassciCircuitBreaker $breaker): void
    {
        $breaker->reportFailure();
        $breaker->reportFailure();
        $breaker->reportFailure();
    }
}

/**
 * Double de test substituable (§1.6 L) : retourne une URL de base contrôlée sans
 * dépendre du résolveur 3-tiers concret (`final`, non mockable).
 */
final class FakeTargetResolver implements KlassciTargetResolver
{
    public function __construct(private readonly ?string $baseUrl) {}

    public function baseUrl(): ?string
    {
        return $this->baseUrl;
    }
}
