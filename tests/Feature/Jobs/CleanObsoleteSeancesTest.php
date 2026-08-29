<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CleanObsoleteSeances;
use App\Models\Institution;
use App\Models\Seance;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Issue #516 — `CleanObsoleteSeances`, comportements hors isolation
 * cross-tenant (couverte par {@see CleanObsoleteSeancesTenantIsolationTest}) :
 * skip propre d'une institution sans config KLASSCI exploitable (R2), et
 * budget-temps de drain appliqué PAR institution (R5, non-régression #539).
 */
final class CleanObsoleteSeancesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_institution_without_usable_klassci_config_is_skipped_others_still_processed(): void
    {
        // `klassci_api_url` est NOT NULL en base (colonne `string`, jamais
        // vraiment vide en pratique) — le cas réaliste de config non
        // exploitable est un `klassci_api_token` absent (colonne nullable).
        $noConfig = Institution::factory()->create([
            'klassci_api_url' => 'https://no-token.klassci.test',
            'klassci_api_token_encrypted' => null,
        ]);
        $withConfig = Institution::factory()->create([
            'klassci_api_url' => 'https://school-ok.klassci.test',
            'klassci_api_token_encrypted' => 'token-ok',
        ]);

        $seanceNoConfig = Seance::factory()->forInstitution($noConfig)->create([
            'klassci_seance_id' => 111,
            'is_active' => true,
        ]);
        $seanceWithConfig = Seance::factory()->forInstitution($withConfig)->create([
            'klassci_seance_id' => 222,
            'is_active' => true,
        ]);

        // La 222 n'existe plus côté KLASSCI (404) → doit être archivée. La 111
        // (institution sans config) ne doit générer AUCUN appel HTTP.
        Http::fake([
            'https://school-ok.klassci.test/seances/222' => Http::response([], 404),
        ]);

        app(TenantManager::class)->reset();
        $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchSync(new CleanObsoleteSeances);

        self::assertTrue(
            (bool) $seanceNoConfig->fresh()->is_active,
            "Institution sans config KLASSCI exploitable : ses séances ne doivent PAS être touchées (ni archivées à tort, ni d'appel réseau).",
        );
        self::assertFalse(
            (bool) $seanceWithConfig->fresh()->is_active,
            "L'institution avec config valide doit être traitée normalement (404 confirmé → archivage) malgré le skip de l'autre.",
        );
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/seances/111'));
    }

    /**
     * Revue de code #516 — isolation de panne PAR INSTITUTION : l'ancien code
     * isolait les pannes par SÉANCE ; le refactor par institution doit
     * préserver la même garantie à son niveau. Une URL KLASSCI truthy mais
     * mal formée (`klassci_api_url` non vide mais syntaxiquement invalide)
     * passe le garde-fou "config absente" (R2, vérification de présence
     * uniquement) mais fait lever une exception au moment de la construction
     * de la requête HTTP — cette institution doit être ignorée SANS bloquer
     * les autres (`processInstitution()` catche `\Throwable`).
     */
    public function test_institution_with_malformed_klassci_url_is_isolated_others_still_processed(): void
    {
        $malformed = Institution::factory()->create([
            // Truthy (passe `! $institution->klassci_api_url`) mais syntaxiquement
            // invalide — fait échouer la construction de la requête HTTP pooled.
            'klassci_api_url' => 'not-a-valid-url',
            'klassci_api_token_encrypted' => 'token-malformed',
        ]);
        $healthy = Institution::factory()->create([
            'klassci_api_url' => 'https://school-ok.klassci.test',
            'klassci_api_token_encrypted' => 'token-ok',
        ]);

        $seanceMalformed = Seance::factory()->forInstitution($malformed)->create([
            'klassci_seance_id' => 111,
            'is_active' => true,
        ]);
        $seanceHealthy = Seance::factory()->forInstitution($healthy)->create([
            'klassci_seance_id' => 222,
            'is_active' => true,
        ]);

        Http::fake([
            'https://school-ok.klassci.test/seances/222' => Http::response([], 404),
        ]);

        app(TenantManager::class)->reset();
        $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchSync(new CleanObsoleteSeances);

        self::assertTrue(
            (bool) $seanceMalformed->fresh()->is_active,
            "Institution à l'URL KLASSCI mal formée : son exception ne doit ni la faire archiver à tort, ni interrompre le job.",
        );
        self::assertFalse(
            (bool) $seanceHealthy->fresh()->is_active,
            "L'institution saine doit être traitée normalement (404 confirmé → archivage) malgré l'échec de l'autre.",
        );
        self::assertNull(
            app(TenantManager::class)->get(),
            'Aucun tenant résiduel après le job, même quand une institution a levé une exception en cours de route.',
        );
    }

    /**
     * Audit `spec-architect` #516 (HIGH) — l'archivage faisait un `save()`
     * PAR séance confirmée supprimée (N+1 SQL en écriture, alors même que le
     * N+1 HTTP venait d'être éliminé). Pattern baseline-vs-afterGrowth
     * (#503/#517) : le nombre de requêtes `UPDATE ... seances` doit rester
     * CONSTANT (1) que 2 ou 10 séances soient archivées dans le même lot.
     */
    public function test_archiving_multiple_confirmed_deleted_seances_uses_one_bulk_update_not_one_per_seance(): void
    {
        $institution = Institution::factory()->create([
            'klassci_api_url' => 'https://school-ok.klassci.test',
            'klassci_api_token_encrypted' => 'token-ok',
        ]);

        $seances = collect(range(1, 10))->map(fn (int $klassciId) => Seance::factory()->forInstitution($institution)->create([
            'klassci_seance_id' => $klassciId,
            'is_active' => true,
        ]));

        // Les 10 séances ont réellement disparu côté KLASSCI (404 confirmé).
        Http::fake(
            $seances->mapWithKeys(fn (Seance $seance) => [
                "https://school-ok.klassci.test/seances/{$seance->klassci_seance_id}" => Http::response([], 404),
            ])->all(),
        );

        app(TenantManager::class)->reset();
        DB::enableQueryLog();
        $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchSync(new CleanObsoleteSeances);
        $updateQueries = collect(DB::getQueryLog())->filter(
            fn (array $log): bool => str_starts_with(strtoupper((string) $log['query']), 'UPDATE') && str_contains((string) $log['query'], 'seances'),
        );
        DB::disableQueryLog();
        DB::flushQueryLog();

        self::assertCount(
            1,
            $updateQueries,
            "10 séances archivées dans le même lot doivent produire 1 SEUL UPDATE (bulk), pas 10 — trouvé : {$updateQueries->count()}.",
        );
        foreach ($seances as $seance) {
            self::assertFalse((bool) $seance->fresh()->is_active, "Séance klassci_seance_id={$seance->klassci_seance_id} doit être archivée.");
        }
    }

    public function test_budget_is_applied_per_institution_remaining_institutions_deferred_to_next_run(): void
    {
        $instA = Institution::factory()->create([
            'klassci_api_url' => 'https://school-a.klassci.test',
            'klassci_api_token_encrypted' => 'token-a',
        ]);
        $instB = Institution::factory()->create([
            'klassci_api_url' => 'https://school-b.klassci.test',
            'klassci_api_token_encrypted' => 'token-b',
        ]);

        $seanceA = Seance::factory()->forInstitution($instA)->create([
            'klassci_seance_id' => 111,
            'is_active' => true,
        ]);
        $seanceB = Seance::factory()->forInstitution($instB)->create([
            'klassci_seance_id' => 222,
            'is_active' => true,
        ]);

        // Les deux séances ont réellement disparu côté KLASSCI (404 confirmé).
        Http::fake([
            'https://school-a.klassci.test/seances/111' => Http::response([], 404),
            'https://school-b.klassci.test/seances/222' => Http::response([], 404),
        ]);

        // Budget 0 : la 1ère institution traitée épuise déjà le budget → la
        // 2e institution est reportée au run suivant, jamais touchée ce run-ci.
        app(TenantManager::class)->reset();
        $job = new CleanObsoleteSeances;
        $job->drainBudgetSeconds = 0;
        $this->app->call([$job, 'handle']);

        $archivedAfterFirstRun = ! $seanceA->fresh()->is_active || ! $seanceB->fresh()->is_active;
        $bothArchivedAfterFirstRun = ! $seanceA->fresh()->is_active && ! $seanceB->fresh()->is_active;

        self::assertTrue($archivedAfterFirstRun, 'Budget 0 : au moins une institution doit avoir été traitée avant l\'arrêt.');
        self::assertFalse($bothArchivedAfterFirstRun, 'Budget 0 : au plus une institution traitée par run — la seconde est reportée.');

        // Run suivant (budget par défaut) : job idempotent, reprend le travail
        // restant — les deux séances finissent archivées.
        app(TenantManager::class)->reset();
        $this->app->call([new CleanObsoleteSeances, 'handle']);

        self::assertFalse((bool) $seanceA->fresh()->is_active, 'Reprise au drain suivant : séance A archivée.');
        self::assertFalse((bool) $seanceB->fresh()->is_active, 'Reprise au drain suivant : séance B archivée.');
    }
}
