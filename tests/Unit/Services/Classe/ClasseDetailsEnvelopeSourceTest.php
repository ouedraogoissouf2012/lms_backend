<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Classe;

use App\Models\User;
use App\Services\Classe\ClasseDetailsQueryService;
use App\Services\Classe\ClasseSecondaryDataFetcher;
use App\Services\KlassciProxyService;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Source des blocs « détails classe » — non-régression du geste #669.
 *
 * KLASSCI livre `GET classes/{id}` sous forme d'ENVELOPPE contenant déjà
 * `classe`, `etudiants`, `matieres`, `evaluations`, `emploi_temps_semaine` et
 * `statistiques` (forme vérifiée par curl). Redemander ces blocs par des appels
 * séparés est au mieux superflu, au pire FAUX : le catalogue global
 * `matieres?filiere_id=…&niveau_id=…` ignore ses filtres et renvoyait les 452
 * matières de tout l'établissement en guise de « matières de la classe ».
 *
 * Ces tests verrouillent la SOURCE de chaque bloc : la donnée déjà en main, et
 * aucun second appel.
 *
 * @see app/Services/Classe/ClasseDetailsQueryService.php
 * @see tests/Unit/Services/Classe/ClasseDetailsQueryServiceTest.php (filtre #257)
 */
#[CoversClass(ClasseDetailsQueryService::class)]
final class ClasseDetailsEnvelopeSourceTest extends TestCase
{
    private const CLASSE_ID = 1;
    private const TOKEN = 'tok-envelope';

    /** Endpoints KLASSCI réellement sollicités pendant l'appel sous test. @var list<string> */
    private array $calledEndpoints = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Utilisateur in-memory ; le token transite par le cast chiffré. */
    private function makeUser(): User
    {
        $user = new User();
        $user->id = 77;
        $user->klassci_token = self::TOKEN;

        return $user;
    }

    /**
     * Enveloppe nominale de la classe 1 (B2 COM) : filière et niveau sont
     * RENSEIGNÉS à dessein — sans eux, l'ancien `fetchMatieres` sortait en `[]`
     * avant tout appel réseau et le test aurait passé pour la mauvaise raison.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function envelope(array $overrides = []): array
    {
        return array_merge([
            'classe' => [
                'id' => self::CLASSE_ID,
                'nom' => 'B2 COM',
                'nombre_places' => 30,
                'filiere' => ['id' => 3, 'nom' => 'Communication'],
                'niveau' => ['id' => 2, 'nom' => 'B2'],
            ],
            'etudiants' => [['id' => 11], ['id' => 12], ['id' => 13]],
            'matieres' => [['id' => 101], ['id' => 102], ['id' => 103]],
            'emploi_temps_semaine' => [['id' => 501], ['id' => 502]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function invokeWithEnvelope(array $envelope): array
    {
        $this->calledEndpoints = [];

        /** @var KlassciProxyService&Mockery\MockInterface $klassci */
        $klassci = Mockery::mock(KlassciProxyService::class);
        $klassci->shouldReceive('requestWithUserToken')
            ->andReturnUsing(function (string $token, string $endpoint, string $method) use ($envelope): array {
                $this->calledEndpoints[] = $endpoint;

                return str_starts_with($endpoint, 'classes/')
                    ? ['data' => $envelope]
                    : ['data' => []];
            });

        $service = new ClasseDetailsQueryService(
            $klassci,
            new ClasseSecondaryDataFetcher($klassci, new NullLogger()),
            new NullLogger(),
        );

        return $service->getDetailsForUser(self::CLASSE_ID, $this->makeUser());
    }

    /** Vrai si un endpoint sollicité commence par le préfixe donné. */
    private function calledEndpointStartingWith(string $prefix): bool
    {
        foreach ($this->calledEndpoints as $endpoint) {
            if (str_starts_with($endpoint, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function test_matieres_come_from_envelope_not_from_global_catalog(): void
    {
        $result = $this->invokeWithEnvelope($this->envelope());

        self::assertSame(200, $result['status']);
        self::assertCount(3, $result['payload']['data']['matieres_disponibles']);
        self::assertSame(
            [101, 102, 103],
            array_column($result['payload']['data']['matieres_disponibles'], 'id'),
        );
        self::assertSame(3, $result['payload']['data']['statistiques']['nombre_matieres']);
        self::assertFalse(
            $this->calledEndpointStartingWith('matieres?'),
            'Le catalogue global des matières ne doit plus être sollicité : la donnée est déjà dans l\'enveloppe.',
        );
    }

    public function test_emploi_temps_comes_from_envelope_without_second_call(): void
    {
        $result = $this->invokeWithEnvelope($this->envelope());

        self::assertCount(2, $result['payload']['data']['emploi_temps_semaine']);
        self::assertSame(2, $result['payload']['data']['statistiques']['nombre_seances_semaine']);
        self::assertFalse(
            $this->calledEndpointStartingWith('emploi-temps?'),
            'L\'emploi du temps de la semaine est déjà livré par l\'enveloppe.',
        );
    }

    public function test_missing_block_is_reported_as_null_never_as_a_zero_measure(): void
    {
        // Anomalie amont : KLASSCI ne fournit pas le bloc. Compter 0 ferait passer
        // une donnée ABSENTE pour une mesure (« 0 matière »). La stat vaut null,
        // et l'UI affiche « — ».
        $envelope = $this->envelope();
        unset($envelope['matieres'], $envelope['emploi_temps_semaine']);

        $result = $this->invokeWithEnvelope($envelope);

        self::assertNull($result['payload']['data']['statistiques']['nombre_matieres']);
        self::assertNull($result['payload']['data']['statistiques']['nombre_seances_semaine']);
        // La LISTE reste un tableau (contrat de forme du payload), c'est la MESURE
        // qui porte l'absence.
        self::assertSame([], $result['payload']['data']['matieres_disponibles']);
        self::assertSame([], $result['payload']['data']['emploi_temps_semaine']);
    }
}
