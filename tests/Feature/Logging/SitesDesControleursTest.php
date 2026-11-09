<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Monolog\Level;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * Un contrôleur qui attrape une exception la journalise par `CaughtExceptionLog`,
 * sur la route réelle : le même message qu'avant, en `error`, avec la
 * description de l'exception et la trace, sur le journal tel qu'il est écrit.
 *
 * L'échec est réel : la première requête sur `seances` échoue
 * (`beforeExecuting`), sous le service, sans double.
 *
 * Les séances à venir ne sont pas ici : une `QueryException` y est une
 * `RuntimeException`, prise par `renderKlassciFailure`, qui la rapporte (#1000).
 */
final class SitesDesControleursTest extends TestCase
{
    use ActsAsTenantUser;
    use CapturesWrittenLog;
    use RefreshDatabase;

    private const TEMOIN = 'temoin-valeur-liee-controleurs';

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function routes(): iterable
    {
        yield 'historique des séances' => ['/api/lms/seances/history', 'Erreur récupération historique séances'];
    }

    #[DataProvider('routes')]
    public function test_l_exception_attrapee_est_journalisee_par_l_aide(string $uri, string $message): void
    {
        $this->disableKlassciMiddleware();
        $ecole = Institution::factory()->create();
        $coordinateur = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'coordinateur']);
        $client = $this->asTenant($coordinateur);
        $this->captureWrittenLog();
        $this->faireEchouerLaPremiereRequeteSurLesSeances();

        $client->getJson($uri)->assertStatus(500)->assertJsonPath('success', false);

        $erreurs = array_values(array_filter(
            $this->recordsAtLeast(Level::Error),
            static fn ($r): bool => $r->message === $message,
        ));
        self::assertCount(1, $erreurs, "Le site écrit « {$message} » une fois.");
        self::assertSame(QueryException::class, $erreurs[0]->context['exception_class'] ?? null);
        self::assertStringContainsString('seances', (string) ($erreurs[0]->context['sql'] ?? ''));
        self::assertNotEmpty($erreurs[0]->context['trace'] ?? null);
        $this->assertNoWitnessWritten(self::TEMOIN);
    }

    private function faireEchouerLaPremiereRequeteSurLesSeances(): void
    {
        $dejaLevee = false;
        DB::connection()->beforeExecuting(static function (string $sql, array $liaisons) use (&$dejaLevee): void {
            if ($dejaLevee || ! str_contains($sql, 'seances')) {
                return;
            }
            $dejaLevee = true;

            throw new QueryException('sqlite', $sql, [self::TEMOIN, ...$liaisons], new PDOException('verrou'));
        });
    }
}
