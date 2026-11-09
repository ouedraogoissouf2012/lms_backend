<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Support\Logging\DescribedFailedJobProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le stockage des jobs échoués écrit l'exception par sa description
 * (`DescribedFailedJobProvider`, lié par `QueueFailureServiceProvider`).
 *
 * Le chemin est le vrai : un job échoue sous `queue:work`, dont l'écouteur de
 * `JobFailed` (`WorkCommand::logFailedJob`) écrit la ligne `failed_jobs`.
 */
final class FailedJobDescriptionTest extends TestCase
{
    use RefreshDatabase;

    public const TEMOIN = 'temoin-valeur-liee-job';

    /** @return iterable<string, array{0: bool}> */
    public static function formes(): iterable
    {
        yield 'exception SQL' => [false];
        yield 'exception SQL enveloppée' => [true];
    }

    #[DataProvider('formes')]
    public function test_la_ligne_du_job_echoue_decrit_l_exception(bool $enveloppee): void
    {
        // Connexion explicite : la connexion par défaut varie selon la jambe de CI
        // (`database` ou `redis`), et `queue:work database` ne lit que la sienne.
        JobQuiEchoue::dispatch($enveloppee)->onConnection('database');

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);

        $lignes = DB::table('failed_jobs')->get();
        self::assertCount(1, $lignes);
        $ecrite = (string) $lignes[0]->exception;
        self::assertStringNotContainsString(self::TEMOIN, $ecrite);
        self::assertStringContainsString('select * from articles where code = ?', $ecrite);
        self::assertStringContainsString($enveloppee ? 'DeadlockException' : 'QueryException', $ecrite);
        $payload = json_decode((string) $lignes[0]->payload, true);
        self::assertSame(JobQuiEchoue::class, is_array($payload) ? ($payload['displayName'] ?? null) : null, 'Le payload est intact : le job reste relançable.');
    }

    public function test_le_decorateur_delegue_chaque_methode_des_interfaces(): void
    {
        $stockage = $this->app->make('queue.failer');
        self::assertInstanceOf(DescribedFailedJobProvider::class, $stockage);
        self::assertInstanceOf(FailedJobProviderInterface::class, $stockage);

        $uuid = $stockage->log('database', 'default', (string) json_encode(['uuid' => 'uuid-1', 'job' => 'x']), new \RuntimeException('Disque plein.'));

        // Chaque argument est transmis : une file ou une connexion inconnue ne rend rien.
        self::assertSame('uuid-1', $uuid);
        self::assertSame(['uuid-1'], $stockage->ids());
        self::assertSame(['uuid-1'], $stockage->ids('default'));
        self::assertSame([], $stockage->ids('autre-file'));
        self::assertCount(1, $stockage->all());
        $trouve = $stockage->find('uuid-1');
        self::assertIsObject($trouve);
        self::assertSame('uuid-1', $trouve->id ?? null);
        self::assertSame(1, $stockage->count());
        self::assertSame(1, $stockage->count('database', 'default'));
        self::assertSame(0, $stockage->count('autre-connexion'));
        self::assertTrue($stockage->forget('uuid-1'));
        self::assertSame(0, $stockage->count());

        $stockage->log('database', 'default', (string) json_encode(['uuid' => 'uuid-2', 'job' => 'x']), new \RuntimeException('Disque plein.'));
        self::assertSame(0, $stockage->prune(now()->subMinute()), 'Une ligne plus récente que la borne reste.');
        self::assertSame(1, $stockage->prune(now()->addMinute()));

        $stockage->log('database', 'default', (string) json_encode(['uuid' => 'uuid-3', 'job' => 'x']), new \RuntimeException('Disque plein.'));
        $stockage->flush(1);
        self::assertSame(1, $stockage->count(), 'flush(1) garde une ligne de moins d\'une heure.');
        $stockage->flush();
        self::assertSame(0, $stockage->count());
    }
}

/** Un job qui échoue sur une requête SQL liée à une valeur témoin. */
final class JobQuiEchoue implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly bool $enveloppee) {}

    public function handle(): void
    {
        $sql = new QueryException('mysql', 'select * from articles where code = ?', [FailedJobDescriptionTest::TEMOIN], new PDOException('verrou'));

        throw $this->enveloppee ? new DeadlockException($sql->getMessage(), 0, $sql) : $sql;
    }
}
