<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\Institution;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * #1671 — les quatre opérations du calendrier suivent `docs/openapi.yaml` : chaque succès (un événement de toute
 * l'école, un événement de classes choisies), et les refus décrits (422 par champ, 422 `classe_hors_ecole`, 404,
 * 403 de la gestion).
 *
 * Jeton Bearer RÉEL, par `asTenant()`.
 */
final class CalendrierContractTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const RACINE = '/lms/calendrier';

    private const UN = '/lms/calendrier/{evenementId}';

    private Institution $ecole;

    private User $coordinateur;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-28 10:00:00');
        $this->disableKlassciMiddleware();
        $this->ecole = $this->ecole();
        $this->coordinateur = $this->membre($this->ecole, 'coordinateur');
    }

    public function test_le_cycle_d_un_evenement_suit_la_spec(): void
    {
        $classe = $this->classe($this->ecole, 'Terminale A');
        $id = (int) $this->contrat($this->api()->postJson('/api'.self::RACINE, $this->saisie())->assertCreated(), self::RACINE, 'post')->json('data.id');
        $un = str_replace('{evenementId}', (string) $id, self::UN);

        $this->contrat($this->api()->putJson("/api{$un}", $this->saisie() + ['classe_ids' => [$classe->id]])->assertOk(), self::UN, 'put');
        $this->contrat($this->api()->getJson('/api'.self::RACINE.'?du=2026-09-01&au=2027-07-31')->assertOk(), self::RACINE, 'get');
        $this->contrat($this->api()->deleteJson("/api{$un}")->assertOk(), self::UN, 'delete');
    }

    public function test_les_refus_suivent_la_spec(): void
    {
        $this->contrat($this->api()->getJson('/api'.self::RACINE)->assertUnprocessable(), self::RACINE, 'get');
        $this->contrat($this->api()->postJson('/api'.self::RACINE, ['type' => 'excursion'] + $this->saisie())->assertUnprocessable(), self::RACINE, 'post');
        $etrangere = $this->classe($this->ecole(), 'Ailleurs');
        $this->contrat($this->api()->postJson('/api'.self::RACINE, $this->saisie() + ['classe_ids' => [$etrangere->id]])
            ->assertUnprocessable()->assertJsonPath('reason', 'classe_hors_ecole'), self::RACINE, 'post');
        $this->contrat($this->api()->putJson('/api/lms/calendrier/424242', $this->saisie())->assertNotFound(), self::UN, 'put');
        $this->contrat($this->asTenant($this->membre($this->ecole, 'enseignant'))->postJson('/api'.self::RACINE, $this->saisie())
            ->assertForbidden(), self::RACINE, 'post');
    }

    private function api(): static
    {
        return $this->asTenant($this->coordinateur);
    }

    /** @return array<string, mixed> */
    private function saisie(): array
    {
        return ['type' => 'vacances', 'titre' => 'Vacances de Noël', 'debut' => '2026-12-19', 'fin' => '2027-01-04'];
    }

    private function contrat(TestResponse $reponse, string $chemin, string $methode): TestResponse
    {
        $this->assertMatchesOpenApiResponse($reponse, $chemin, $methode);

        return $reponse;
    }
}
