<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Logging;

use App\Support\Logging\ExceptionDescription;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * La description d'une exception ne porte ni le message d'une exception SQL, ni
 * ses valeurs liées, ni aucun objet exception (le formateur en écrirait le
 * message et la chaîne `previous`).
 */
final class ExceptionDescriptionTest extends TestCase
{
    private const TEMOIN = 'temoin.description@exemple.invalid';

    public function test_une_exception_sql_est_decrite_sans_message_ni_valeurs(): void
    {
        $description = (new ExceptionDescription)->describe($this->echecSql());

        self::assertSame(['exception_class', 'connection', 'sql', 'code', 'driver_code'], array_keys($description));
        self::assertSame(QueryException::class, $description['exception_class']);
        self::assertSame('select * from users where email = ?', $description['sql']);
        self::assertSame('sqlite', $description['connection']);
        $this->assertSansTemoin($description);
    }

    /** `ManagesTransactions` enveloppe la QueryException d'un interblocage en recopiant son message. */
    public function test_une_exception_sql_enveloppee_est_decrite_sans_message_ni_valeurs(): void
    {
        $sql = $this->echecSql();
        $description = (new ExceptionDescription)->describe(new DeadlockException($sql->getMessage(), 0, $sql));

        self::assertSame(DeadlockException::class, $description['exception_class']);
        self::assertSame('select * from users where email = ?', $description['sql']);
        $this->assertSansTemoin($description);
    }

    public function test_une_exception_hors_sql_garde_sa_classe_et_son_message(): void
    {
        $exception = new RuntimeException('Disque plein.');

        self::assertSame(
            ['exception_class' => RuntimeException::class, 'error' => 'Disque plein.'],
            (new ExceptionDescription)->describe($exception),
        );
        self::assertNull((new ExceptionDescription)->queryFailure($exception));
    }

    private function echecSql(): QueryException
    {
        return new QueryException(
            'sqlite',
            'select * from users where email = ?',
            [self::TEMOIN],
            new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
        );
    }

    /** @param  array<string, mixed>  $description */
    private function assertSansTemoin(array $description): void
    {
        foreach ($description as $valeur) {
            self::assertIsNotObject($valeur);
        }
        self::assertStringNotContainsString(self::TEMOIN, (string) json_encode($description));
    }
}
