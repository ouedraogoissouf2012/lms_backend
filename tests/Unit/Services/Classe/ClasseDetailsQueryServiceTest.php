<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Classe;

use App\Models\User;
use App\Services\Classe\ClasseDetailsQueryService;
use App\Services\Classe\ClasseSecondaryDataFetcher;
use App\Services\KlassciProxyService;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Issue #257 — exclusion silencieuse d'un étudiant au statut manquant/malformé.
 *
 * `fetchEtudiantsActifs()` conserve le filtre **strict** (`statut === 'actif'`),
 * mais distingue désormais deux familles d'exclusion :
 *   1. Exclusion *intentionnelle* (`statut` valide ≠ 'actif', ex. 'inactif') →
 *      silencieuse, c'est le métier nominal.
 *   2. Exclusion *anormale* (`statut` absent / null / vide / non-string) →
 *      tracée via un warning structuré (id étudiant + id classe), pour qu'un
 *      effectif sous-compté soit diagnosticable au lieu de disparaître sans
 *      laisser de trace.
 *
 * Tests unitaires en isolation : KLASSCI + fetchers secondaires mockés, logger
 * espionné (PSR-3, injecté par DI).
 *
 * @see app/Services/Classe/ClasseDetailsQueryService.php
 */
#[CoversClass(ClasseDetailsQueryService::class)]
final class ClasseDetailsQueryServiceTest extends TestCase
{
    private const CLASSE_ID = 42;
    private const TOKEN = 'tok-xyz';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Étudiant authentifié, in-memory (aucune écriture DB). Le token transite
     * par le cast chiffré `klassci_token_encrypted`.
     */
    private function makeUser(): User
    {
        $user = new User();
        $user->id = 99;
        $user->klassci_token = self::TOKEN;

        return $user;
    }

    /**
     * @param  array<int, array<string, mixed>>  $etudiants
     */
    private function makeKlassci(array $etudiants): KlassciProxyService&MockInterface
    {
        $classe = [
            'id' => self::CLASSE_ID,
            'nombre_places' => 30,
            'filiere' => ['id' => 1],
            'niveau' => ['id' => 2],
        ];

        /** @var KlassciProxyService&MockInterface $klassci */
        $klassci = Mockery::mock(KlassciProxyService::class);

        // Défaut : blocs secondaires (emploi-temps, évaluations, matières) vides.
        // Seule la frontière externe KLASSCI est mockée ; le vrai
        // ClasseSecondaryDataFetcher (final) est conservé.
        $klassci->shouldReceive('requestWithUserToken')
            ->andReturn(['data' => []])
            ->byDefault();

        $klassci->shouldReceive('requestWithUserToken')
            ->with(self::TOKEN, 'classes/' . self::CLASSE_ID . '?with=filiere,niveau', 'GET')
            ->andReturn(['data' => $classe]);
        $klassci->shouldReceive('requestWithUserToken')
            ->with(self::TOKEN, 'classes/' . self::CLASSE_ID . '/etudiants', 'GET')
            ->andReturn(['data' => $etudiants]);

        return $klassci;
    }

    /**
     * @param  array<int, array<string, mixed>>  $etudiants
     * @return array{result: array{status: int, payload: array<string, mixed>}, logger: LoggerInterface&MockInterface}
     */
    private function invokeService(array $etudiants): array
    {
        /** @var LoggerInterface&MockInterface $logger */
        $logger = Mockery::spy(LoggerInterface::class);

        $klassci = $this->makeKlassci($etudiants);

        // Collaborateur réel : on n'isole que la frontière KLASSCI. Son logger
        // est neutralisé (NullLogger) pour que seuls les warnings du service
        // sous test soient observés par le spy.
        $secondary = new ClasseSecondaryDataFetcher($klassci, new NullLogger());

        $service = new ClasseDetailsQueryService($klassci, $secondary, $logger);

        $result = $service->getDetailsForUser(self::CLASSE_ID, $this->makeUser());

        return ['result' => $result, 'logger' => $logger];
    }

    public function test_excludes_and_warns_when_statut_is_null(): void
    {
        ['result' => $result, 'logger' => $logger] = $this->invokeService([
            ['id' => 7, 'statut' => null],
            ['id' => 8, 'statut' => 'actif'],
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $result['payload']['data']['statistiques']['nombre_etudiants']);

        $logger->shouldHaveReceived('warning')
            ->withArgs(static function (string $message, array $context): bool {
                return $context['classe_id'] === self::CLASSE_ID
                    && $context['etudiant_id'] === 7;
            })
            ->once();
    }

    public function test_excludes_and_warns_when_statut_key_is_missing(): void
    {
        ['result' => $result, 'logger' => $logger] = $this->invokeService([
            ['id' => 9],
            ['id' => 8, 'statut' => 'actif'],
        ]);

        self::assertSame(1, $result['payload']['data']['statistiques']['nombre_etudiants']);

        $logger->shouldHaveReceived('warning')
            ->withArgs(static function (string $message, array $context): bool {
                return $context['classe_id'] === self::CLASSE_ID
                    && $context['etudiant_id'] === 9;
            })
            ->once();
    }

    public function test_excludes_and_warns_when_statut_is_empty_string(): void
    {
        ['result' => $result, 'logger' => $logger] = $this->invokeService([
            ['id' => 11, 'statut' => ''],
            ['id' => 8, 'statut' => 'actif'],
        ]);

        self::assertSame(1, $result['payload']['data']['statistiques']['nombre_etudiants']);

        $logger->shouldHaveReceived('warning')
            ->withArgs(static fn (string $message, array $context): bool => $context['etudiant_id'] === 11)
            ->once();
    }

    public function test_does_not_warn_for_intentionally_inactive_student(): void
    {
        // Exclusion métier nominale : un statut valide ≠ 'actif' ne doit PAS
        // générer de bruit de log (sinon warning-spam à chaque classe).
        ['result' => $result, 'logger' => $logger] = $this->invokeService([
            ['id' => 8, 'statut' => 'actif'],
            ['id' => 10, 'statut' => 'inactif'],
        ]);

        self::assertSame(1, $result['payload']['data']['statistiques']['nombre_etudiants']);

        $logger->shouldNotHaveReceived('warning');
    }

    public function test_keeps_all_active_students_without_warning(): void
    {
        ['result' => $result, 'logger' => $logger] = $this->invokeService([
            ['id' => 1, 'statut' => 'actif'],
            ['id' => 2, 'statut' => 'actif'],
        ]);

        self::assertSame(2, $result['payload']['data']['statistiques']['nombre_etudiants']);

        $logger->shouldNotHaveReceived('warning');
    }
}
