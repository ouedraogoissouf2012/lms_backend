<?php

declare(strict_types=1);

namespace Tests\Feature\Proxy;

use App\Enums\InstitutionMode;
use App\Services\KlassciProxyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Monolog\Level;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * Une défaillance interne d'un tableau de bord `/proxy` est rapportée UNE fois,
 * sans le message ni les valeurs de la requête (#948).
 *
 * Les deux tableaux de bord écrivaient eux-mêmes le message de l'exception
 * avant de la rendre. Depuis que le rendu des erreurs proxy rapporte la panne
 * (lot 3a), elle était écrite deux fois, et l'une des deux portait le SQL avec
 * ses valeurs liées.
 */
final class ProxyDashboardPanneTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const TEMOIN_SQL = 'temoin.sql.tableau@exemple.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->app['config']->set('app.debug', false);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function tableaux(): iterable
    {
        yield 'élève' => ['/api/proxy/me/dashboard', 'etudiant'];
        yield 'enseignant' => ['/api/proxy/me/teacher-dashboard', 'enseignant'];
    }

    #[DataProvider('tableaux')]
    public function test_une_panne_de_base_est_rapportee_une_fois_sans_ses_valeurs(string $route, string $role): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')->once()->andThrow(new QueryException(
                'sqlite',
                'select * from cache where email = ?',
                [self::TEMOIN_SQL],
                new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
            ));
        });
        $lecteur = $this->membre($this->ecole(InstitutionMode::Klassci), $role);
        $lecteur->forceFill(['klassci_token' => 'jeton-personnel'])->save();
        $this->captureWrittenLog();

        $this->asTenant($lecteur)
            ->getJson($route)
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Service indisponible. Veuillez réessayer.']);

        $ecrits = $this->recordsAtLeast(Level::Error);
        self::assertCount(1, $ecrits, 'La panne doit être écrite une fois.');
        self::assertSame('select * from cache where email = ?', $ecrits[0]->context['sql'] ?? null);
        $this->assertNoWitnessWritten(self::TEMOIN_SQL);
    }
}
