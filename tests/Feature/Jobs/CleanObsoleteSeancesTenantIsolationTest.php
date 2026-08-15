<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CleanObsoleteSeances;
use App\Models\Institution;
use App\Models\Seance;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Issue #516 — `CleanObsoleteSeances` doit vérifier chaque séance contre LE
 * backend KLASSCI de SA PROPRE institution, pas contre une URL globale par
 * défaut choisie arbitrairement (odeur d'isolation confirmée : deux
 * institutions ont des `klassci_api_url` réellement différentes en prod,
 * cf. `database/seeders/InstitutionSeeder.php`).
 *
 * Couvre DEUX bugs cross-tenant distincts trouvés sur cette issue :
 *   1. Le bug d'origine — un seul admin/coordinateur arbitraire, tous tenants.
 *   2. Un bug introduit PENDANT le fix (revue de code) — `KlassciConfigResolver`
 *      mémorisé une fois dans `SeanceExistenceBatchChecker`, réutilisé pour
 *      toutes les institutions d'un même run malgré `TenantManager::set()`.
 *
 * `Http::preventStrayRequests()` + assertions `Http::assertSent()` explicites
 * sur l'hôte réellement appelé (pas seulement sur `is_active` en sortie) :
 * sans ça, une requête envoyée par erreur vers l'URL de A pour vérifier une
 * séance de B tombe sur un pattern non mappé, que `Http::fake()` répond 200
 * par défaut — le test passerait alors pour la MAUVAISE raison (constaté lors
 * de la revue de code du bug #2 ci-dessus).
 */
final class CleanObsoleteSeancesTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->reset();
        parent::tearDown();
    }

    public function test_each_institutions_seance_is_checked_against_its_own_klassci_backend(): void
    {
        Config::set('services.klassci.url', 'https://global-default.klassci.test');
        Config::set('services.klassci.token', 'global-token');

        $schoolA = Institution::factory()->create([
            'klassci_api_url' => 'https://school-a.klassci.test',
            'klassci_api_token_encrypted' => 'token-a',
        ]);
        $schoolB = Institution::factory()->create([
            'klassci_api_url' => 'https://school-b.klassci.test',
            'klassci_api_token_encrypted' => 'token-b',
        ]);

        $seanceA = Seance::factory()->forInstitution($schoolA)->create([
            'klassci_seance_id' => 111,
            'is_active' => true,
        ]);
        $seanceB = Seance::factory()->forInstitution($schoolB)->create([
            'klassci_seance_id' => 222,
            'is_active' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://school-a.klassci.test/seances/111' => Http::response(['data' => ['id' => 111]], 200),
            'https://school-b.klassci.test/seances/222' => Http::response(['data' => ['id' => 222]], 200),
        ]);

        app(TenantManager::class)->reset();
        $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatchSync(new CleanObsoleteSeances);

        $seanceA->refresh();
        $seanceB->refresh();

        self::assertTrue($seanceA->is_active, "La séance de l'institution A doit rester active (200 sur son propre backend).");
        self::assertTrue(
            $seanceB->is_active,
            "La séance de l'institution B doit rester active — elle DOIT être vérifiée contre school-b.klassci.test (200), " .
            'jamais contre le backend global/A (qui renverrait 404 à tort).',
        );

        // `preventStrayRequests()` aurait déjà fait échouer le test si une
        // requête avait été envoyée vers une URL non mappée (ex. school-a
        // pour la séance de B) — ces assertions confirment en plus que
        // l'hôte EFFECTIVEMENT appelé pour chaque séance est le bon.
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://school-a.klassci.test/seances/111'));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://school-b.klassci.test/seances/222'));
    }
}
