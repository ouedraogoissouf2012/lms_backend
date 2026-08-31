<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Classe;

use App\Services\Classe\ClasseSecondaryDataFetcher;
use App\Services\KlassciProxyService;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Appariement classe ↔ évaluation du catalogue KLASSCI.
 *
 * Ce bloc reste le SEUL à exiger un appel séparé : l'enveloppe `classes/{id}`
 * livre bien `evaluations`, mais APPAUVRIES — sans `programmation.window`, dont
 * l'UI tire le badge de fenêtre temporelle (vérifié en réel : 13 évaluations
 * des deux côtés, `window` présent au seul catalogue). On garde donc l'appel,
 * et on fiabilise son filtre.
 *
 * Le filtre comparait `$eval['classe']['id'] === $classeId` en STRICT. KLASSCI
 * renvoie aujourd'hui un entier, mais ses payloads JSON ne le garantissent pas :
 * une seule livraison en chaîne (« 1 ») faisait tomber le filtre à zéro et
 * l'écran affichait « aucune évaluation » — sans erreur, sans trace.
 *
 * @see app/Services/Classe/ClasseSecondaryDataFetcher.php
 */
#[CoversClass(ClasseSecondaryDataFetcher::class)]
final class ClasseEvaluationsFilterTest extends TestCase
{
    private const CLASSE_ID = 1;
    private const TOKEN = 'tok-eval';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<int, array<string, mixed>>  $catalogue
     * @return array<int, array<string, mixed>>
     */
    private function fetchFrom(array $catalogue): array
    {
        /** @var KlassciProxyService&Mockery\MockInterface $klassci */
        $klassci = Mockery::mock(KlassciProxyService::class);
        $klassci->shouldReceive('requestWithUserToken')
            ->with(self::TOKEN, 'evaluations', 'GET')
            ->andReturn(['data' => $catalogue]);

        $fetcher = new ClasseSecondaryDataFetcher($klassci, new NullLogger());

        return $fetcher->fetchEvaluations(self::CLASSE_ID, self::TOKEN);
    }

    public function test_matches_evaluations_when_klassci_sends_integer_ids(): void
    {
        $result = $this->fetchFrom([
            ['id' => 30, 'classe' => ['id' => 1]],
            ['id' => 31, 'classe' => ['id' => 2]],
        ]);

        self::assertSame([30], array_column($result, 'id'));
    }

    public function test_matches_evaluations_when_klassci_sends_string_ids(): void
    {
        // Même payload, identifiants livrés en CHAÎNE : le filtre strict tombait
        // à zéro et l'écran se vidait silencieusement.
        $result = $this->fetchFrom([
            ['id' => 30, 'classe' => ['id' => '1']],
            ['id' => 31, 'classe' => ['id' => '2']],
        ]);

        self::assertSame([30], array_column($result, 'id'));
    }

    public function test_ignores_entries_without_usable_classe_reference(): void
    {
        // Aucune correspondance ne doit être inventée à partir d'une donnée
        // inexploitable (classe absente, scalaire, ou id non numérique).
        $result = $this->fetchFrom([
            ['id' => 30, 'classe' => null],
            ['id' => 31, 'classe' => 'B2 COM'],
            ['id' => 32, 'classe' => ['id' => 'abc']],
            ['id' => 33],
        ]);

        self::assertSame([], $result);
    }
}
