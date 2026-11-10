<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ArchiveOldSeances;
use App\Jobs\AutoCloseEmptySeances;
use App\Jobs\CleanOldEvaluations;
use App\Jobs\DetectDisconnectedParticipants;
use App\Jobs\DispatchLessonPublishedNotifications;
use App\Jobs\DispatchVisioNotification;
use App\Jobs\FinalizeSeanceAttendances;
use App\Jobs\SyncKlassciClasse;
use App\Jobs\SyncKlassciSeances;
use App\Jobs\SyncUserClasses;
use Closure;
use Illuminate\Database\QueryException;
use Monolog\Level;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * L'échec définitif d'un job (`failed()`) est journalisé par
 * `CaughtExceptionLog` : une écriture en `error`, avec le contexte du job, la
 * description de l'exception et la trace, sur le journal tel qu'il est écrit.
 */
final class EchecDefinitifDesJobsTest extends TestCase
{
    use CapturesWrittenLog;

    private const TEMOIN = 'temoin-valeur-liee-jobs';

    /** @return iterable<string, array{0: Closure(): object, 1: array<string, mixed>}> */
    public static function jobs(): iterable
    {
        yield 'ArchiveOldSeances' => [static fn (): object => new ArchiveOldSeances, ['tries' => 3]];
        yield 'AutoCloseEmptySeances' => [static fn (): object => new AutoCloseEmptySeances, []];
        yield 'CleanOldEvaluations' => [static fn (): object => new CleanOldEvaluations, ['tries' => 3]];
        yield 'DetectDisconnectedParticipants' => [static fn (): object => new DetectDisconnectedParticipants, []];
        yield 'DispatchLessonPublishedNotifications' => [static fn (): object => new DispatchLessonPublishedNotifications(11, 12, 'Leçon', 13), []];
        yield 'DispatchVisioNotification' => [static fn (): object => new DispatchVisioNotification(DispatchVisioNotification::STARTING, 42, []), ['kind' => 'starting', 'seance_id' => 42]];
        yield 'FinalizeSeanceAttendances' => [static fn (): object => new FinalizeSeanceAttendances, []];
        yield 'SyncKlassciClasse' => [static fn (): object => new SyncKlassciClasse(7, 8, 9), ['klassci_classe_id' => 7, 'user_id' => 8, 'institution_id' => 9]];
        yield 'SyncKlassciSeances' => [static fn (): object => new SyncKlassciSeances, ['tries' => 3]];
        yield 'SyncUserClasses' => [static fn (): object => new SyncUserClasses(8, 9), ['user_id' => 8, 'institution_id' => 9]];
    }

    /**
     * @param  Closure(): object  $fabrique
     * @param  array<string, mixed>  $contexte
     */
    #[DataProvider('jobs')]
    public function test_l_echec_definitif_est_decrit_par_l_aide(Closure $fabrique, array $contexte): void
    {
        $this->captureWrittenLog();
        $job = $fabrique();

        $job->failed(new QueryException('mysql', 'select * from articles where code = ?', [self::TEMOIN], new PDOException('verrou')));

        $erreurs = $this->recordsAtLeast(Level::Error);
        self::assertCount(1, $erreurs);
        self::assertSame(QueryException::class, $erreurs[0]->context['exception_class'] ?? null);
        self::assertSame('select * from articles where code = ?', $erreurs[0]->context['sql'] ?? null);
        self::assertNotEmpty($erreurs[0]->context['trace'] ?? null);
        foreach ($contexte as $cle => $valeur) {
            self::assertSame($valeur, $erreurs[0]->context[$cle] ?? null, "Contexte du job : {$cle}");
        }
        $this->assertNoWitnessWritten(self::TEMOIN);
    }
}
