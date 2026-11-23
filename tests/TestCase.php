<?php

namespace Tests;

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureKlassciSync;
use App\Http\Middleware\ResolveInstitution;
use App\Models\Classe;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

abstract class TestCase extends BaseTestCase
{
    /**
     * Le garde s'exécute ICI, et pas dans `setUp()`. La différence décide de
     * tout.
     *
     * `setUpTheTestEnvironment()` du framework appelle `refreshApplication()`
     * — donc cette méthode — **ligne 96**, puis `setUpTraits()` **ligne 101**.
     * C'est `setUpTraits()` qui déclenche `RefreshDatabase`, donc
     * `migrate:fresh`, donc `SQLiteBuilder::refreshDatabaseFile()`, c'est-à-dire
     * un `file_put_contents($chemin, '')`.
     *
     * Mon premier jet plaçait le garde après `parent::setUp()`. Mesuré sur un
     * leurre de 48 tables avec une table témoin :
     *
     *     AVANT  tables=48  témoin=1
     *     >>>    « ARRÊT : la suite pointe la base de DÉVELOPPEMENT »
     *     APRÈS  tables=47  témoin=TABLE DÉTRUITE
     *
     * Le garde annonçait au conditionnel — « la viderait » — un accident déjà
     * consommé, et le développeur lisait « j'ai été protégé » pendant que sa
     * base venait d'être remigrée. Un garde qui constate le décès n'est pas un
     * garde.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $this->refuseDEcrireDansLaBaseDeDeveloppement();

        return $app;
    }

    /**
     * Parité d'isolation entre les jambes CI database et redis (#374).
     *
     * En mode database, RefreshDatabase vide la table `cache` entre chaque
     * test — les compteurs du rate-limiter repartent donc de zéro. En mode
     * redis, rien ne vide le store : sans ce flush, les compteurs `throttle`
     * s'accumulent sur toute la suite et les tests reçoivent des 429.
     * No-op hors runtime redis (le poste dev en mode database n'est pas
     * ralenti, et le store array est déjà réinitialisé par process).
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (config('cache.default') === 'redis') {
            $this->app->make('cache')->store('redis')->flush();
        }

        $this->registerTenantScopeProbe();
    }

    /**
     * Dernier rempart : aucune suite ne démarre sur `database/database.sqlite`.
     *
     * Appelé depuis {@see createApplication()}, donc AVANT que `setUpTraits()`
     * ne déclenche `RefreshDatabase`. L'emplacement est la moitié du correctif.
     *
     * ## Ce que ce garde empêche, et qui est arrivé
     *
     * Le 2026-09-03, la base de DÉVELOPPEMENT a été vidée. `RefreshDatabase`
     * migre et tronque sans rien demander : il suffit que la connexion pointe le
     * mauvais fichier une seule fois.
     *
     * Jusqu'à #692, `phpunit.xml` posait `DB_DATABASE` par `<env>`, que PHPUnit
     * applique AVANT l'amorçage — donc quel que soit le fichier de bootstrap.
     * Le premier jet de #692 a retiré cette ligne au profit de
     * `tests/bootstrap.php`. Mesuré sur ce dépôt :
     *
     *     bootstrap du lot ....... database/testing/<empreinte>.sqlite   (correct)
     *     bootstrap étranger ..... database/database.sqlite              (la base de DEV)
     *
     * Et `bootstrap="vendor/autoload.php"` est EXACTEMENT la valeur que
     * `phpunit.xml` portait jusque-là : toute configuration d'exécution
     * PhpStorm ou VS Code créée avant ce commit la reprend telle quelle.
     *
     * ## Pourquoi ici, et pas seulement dans phpunit.xml
     *
     * Le filet déclaratif y a été rétabli, mais il dépend d'un fichier de
     * configuration qu'on peut contourner par un drapeau. Ce garde-ci s'exécute
     * quand la suite tourne, quelle que soit la façon dont elle a été lancée.
     * Une protection qui dépend d'une configuration n'est pas une protection.
     *
     * On échoue FERMÉ : le doute fait arrêter, il ne fait pas continuer.
     */
    private function refuseDEcrireDansLaBaseDeDeveloppement(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        $fichier = config('database.connections.sqlite.database');

        if (! is_string($fichier) || $fichier === ':memory:') {
            return;
        }

        $normalise = str_replace('\\', '/', $fichier);

        if (! str_ends_with($normalise, '/database/database.sqlite')
            && $normalise !== 'database/database.sqlite') {
            return;
        }

        self::fail(
            'ARRÊT : la suite pointe la base de DÉVELOPPEMENT ('.$fichier.').'
            ."\nRefreshDatabase allait la vider — arrêt AVANT toute écriture."
            ."\nC'est l'accident du 2026-09-03."
            ."\nCause probable : la suite a été lancée avec un autre bootstrap que"
            ." tests/bootstrap.php (une configuration d'exécution d'IDE, par exemple)."
            ."\nLancez-la via `vendor/bin/phpunit` ou `php artisan test`, qui lisent phpunit.xml."
        );
    }

    private function registerTenantScopeProbe(): void
    {
        if (Route::has('__test.tenant-scope-probe')) {
            return;
        }

        Route::middleware([
            AssignRequestId::class,
            ResolveInstitution::class,
            'auth:sanctum',
        ])
            ->get('/api/__test/tenant-scope-probe', function () {
                return response()->json([
                    'count' => Classe::query()->count(),
                ]);
            })
            ->name('__test.tenant-scope-probe');
    }

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
