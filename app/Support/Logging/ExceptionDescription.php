<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Ce qu'un journal peut écrire d'une exception, sans l'exception elle-même.
 *
 * Le message d'une `QueryException` porte le SQL avec ses valeurs liées, et les
 * détails de la connexion ; une enveloppe le recopie (la `DeadlockException`
 * d'une transaction imbriquée, une `ViewException`…). Une exception passée dans
 * un contexte de journal, elle, y fait écrire son message ET sa chaîne
 * `previous` par le formateur. D'où cette description, en champs scalaires :
 *
 * - une `QueryException` dans la chaîne : la classe de tête, le SQL à
 *   marqueurs, la connexion, le code et le code du pilote. Ni message, ni
 *   valeurs, ni message du pilote (MySQL 1062 cite la valeur en double) ;
 * - sinon : la classe et le message.
 *
 * C'est le seul lieu de cette règle. Le rapport des exceptions non attrapées
 * (`UncaughtExceptionReporting`) et l'échec définitif de l'import Jibri s'en
 * servent.
 */
final class ExceptionDescription
{
    /**
     * @return array{exception_class: class-string<Throwable>, connection: string, sql: string, code: mixed, driver_code: mixed}|array{exception_class: class-string<Throwable>, error: string}
     */
    public function describe(Throwable $e): array
    {
        return $this->queryFailure($e) ?? [
            'exception_class' => $e::class,
            'error' => $e->getMessage(),
        ];
    }

    /**
     * La description d'un échec SQL, ou null si la chaîne n'en contient pas.
     *
     * @return array{exception_class: class-string<Throwable>, connection: string, sql: string, code: mixed, driver_code: mixed}|null
     */
    public function queryFailure(Throwable $e): ?array
    {
        $query = $this->queryExceptionIn($e);
        if ($query === null) {
            return null;
        }

        return [
            'exception_class' => $e::class,
            'connection' => $query->getConnectionName(),
            'sql' => $query->getSql(),
            'code' => $query->getCode(),
            'driver_code' => $query->errorInfo[1] ?? null,
        ];
    }

    private function queryExceptionIn(Throwable $e): ?QueryException
    {
        for ($maillon = $e; $maillon !== null; $maillon = $maillon->getPrevious()) {
            if ($maillon instanceof QueryException) {
                return $maillon;
            }
        }

        return null;
    }
}
