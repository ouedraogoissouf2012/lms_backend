<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Monolog\Level;
use Monolog\LogRecord;
use PDOException;
use RuntimeException;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * `CaughtExceptionLog` écrit le message du site, son contexte métier et la
 * description de l'exception, sur le journal tel qu'il est écrit.
 */
final class CaughtExceptionLogTest extends TestCase
{
    use CapturesWrittenLog;

    private const TEMOIN = 'temoin-valeur-liee-aide';

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureWrittenLog();
    }

    public function test_error_ecrit_le_site_son_contexte_la_description_et_la_trace(): void
    {
        $this->app->make(CaughtExceptionLog::class)->error('Échec de la lecture', $this->echecSql(), ['seance_id' => 42]);

        $enregistrement = $this->unSeul(Level::Error);
        self::assertSame('Échec de la lecture', $enregistrement->message);
        self::assertSame(42, $enregistrement->context['seance_id']);
        self::assertSame(QueryException::class, $enregistrement->context['exception_class']);
        self::assertSame('select * from articles where code = ?', $enregistrement->context['sql']);
        self::assertNotSame('', $enregistrement->context['trace']);
        self::assertArrayNotHasKey('exception', $enregistrement->context);
        $this->assertNoWitnessWritten(self::TEMOIN);
    }

    public function test_warning_n_a_pas_de_trace(): void
    {
        $this->app->make(CaughtExceptionLog::class)->warning('Repli sur la source locale', new RuntimeException('Délai dépassé.'), ['classe_id' => 7]);

        $enregistrement = $this->unSeul(Level::Warning);
        self::assertSame(
            ['classe_id' => 7, 'exception_class' => RuntimeException::class, 'error' => 'Délai dépassé.'],
            array_diff_key($enregistrement->context, ['request_id' => true]),
        );
    }

    public function test_une_exception_sql_enveloppee_est_decrite_de_meme(): void
    {
        $sql = $this->echecSql();
        $this->app->make(CaughtExceptionLog::class)->warning('Interblocage', new DeadlockException($sql->getMessage(), 0, $sql));

        self::assertSame(DeadlockException::class, $this->unSeul(Level::Warning)->context['exception_class']);
        $this->assertNoWitnessWritten(self::TEMOIN);
    }

    public function test_le_contexte_du_site_n_est_pas_ecrase_par_la_description(): void
    {
        $this->app->make(CaughtExceptionLog::class)->warning('Repli', new RuntimeException('Délai.'), ['error' => 'motif-du-site']);

        self::assertSame('motif-du-site', $this->unSeul(Level::Warning)->context['error']);
    }

    private function echecSql(): QueryException
    {
        return new QueryException('sqlite', 'select * from articles where code = ?', [self::TEMOIN], new PDOException('verrou'));
    }

    private function unSeul(Level $niveau): LogRecord
    {
        $enregistrements = array_values(array_filter(
            $this->recordsAtLeast(Level::Debug),
            static fn (LogRecord $r): bool => $r->level === $niveau,
        ));
        self::assertCount(1, $enregistrements);

        return $enregistrements[0];
    }
}
