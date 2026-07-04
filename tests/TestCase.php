<?php

namespace Tests;

use App\Http\Middleware\EnsureKlassciSync;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Skip the KLASSCI sync middleware for tests that don't need
     * a real connection to the KLASSCI external API.
     *
     * Used by feature tests that focus on FormRequest validation,
     * authorization or HTTP routing — not on the KLASSCI sync flow itself.
     */
    protected function disableKlassciMiddleware(): void
    {
        $this->withoutMiddleware(EnsureKlassciSync::class);
    }

    /**
     * Fait échouer le test si l'action exécute la moindre requête SQL
     * touchant l'une des tables données (match insensible à la casse sur
     * le texte SQL). Preuve automatisée de la spec redis-runtime (#374,
     * Requirement 8) : en runtime Redis, un GET authentifié ne doit émettre
     * AUCUNE requête vers `cache`, `sessions` ou `jobs`.
     *
     * @param  list<string>  $tables
     */
    protected function assertNoQueriesAgainstTables(array $tables, \Closure $action): void
    {
        $matched = [];

        DB::listen(function ($query) use ($tables, &$matched): void {
            foreach ($tables as $table) {
                if (str_contains(strtolower($query->sql), strtolower($table))) {
                    $matched[] = $query->sql;
                }
            }
        });

        $action();

        self::assertSame(
            [],
            $matched,
            'Requête(s) SQL inattendue(s) sur ['.implode(', ', $tables).'] : '.implode(' | ', $matched)
        );
    }
}
