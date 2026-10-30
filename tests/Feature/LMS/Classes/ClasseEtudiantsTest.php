<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Classes;

use App\Exceptions\KlassciUnavailableException;
use App\Exceptions\MissingKlassciTokenException;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * `GET /api/lms/classes/{classeId}/etudiants` — le roster vient de l'enveloppe,
 * et un échec n'est jamais une classe vide.
 *
 * KLASSCI refuse `classes/{id}/etudiants` en 403 à TOUS les rôles (mesuré en
 * #669) ; l'enveloppe `classes/{id}` porte le roster. Cette route appelait
 * encore l'endpoint refusé, écrasait le refus en 500, et l'écran l'affichait
 * « 0 étudiant ». Une réponse 200 sans roster (`{success: false}`) devenait,
 * elle, un 200 « 0 étudiant ».
 *
 * `Http::fake` et non un double de `KlassciProxyService` : c'est l'URL émise qui
 * est en cause. L'ancien test simulait l'endpoint refusé, et passait par
 * `Sanctum::actingAs` (aucun tenant résolu) : il prouvait un appel que la
 * production ne réussit jamais. Ici, jeton porteur réel (`asTenant`).
 *
 * Volet KLASSCI : tous les cas ci-dessous. Volet autonome : un compte sans jeton
 * KLASSCI reçoit le 401 motivé — ce roster n'a pas de source locale (hors lot).
 *
 * Note : le filtre est volontairement permissif (`!isset($statut) || $statut ===
 * 'actif'`), à la différence de `classeDetails` (strict).
 *
 * @see app/Services/Classe/ClasseEtudiantsQueryService.php
 * @see app/Services/Classe/KlassciEnvelopeClasseRoster.php
 */
final class ClasseEtudiantsTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const BASE_URL = 'https://ecole.klassci.test/api/lms';

    private const CLASSE_ID = 42;

    private const ROUTE = '/api/lms/classes/42/etudiants';

    private Institution $ecole;

    private User $enseignant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->ecole = Institution::factory()->create([
            'klassci_api_url' => self::BASE_URL,
            'is_active' => true,
        ]);
        app(TenantManager::class)->set($this->ecole);

        // État de production : au login KLASSCI, le jeton ET l'URL de tenant sont
        // écrits sur le compte (KlassciUserSynchronizer::buildCommonData).
        $this->enseignant = User::factory()->teacher()->for($this->ecole)->create([
            'klassci_token' => 'jeton-enseignant',
            'klassci_tenant_url' => self::BASE_URL,
        ]);
    }

    /**
     * Un seul faux par test : deux `Http::fake()` s'empilent et le PREMIER
     * gagne. L'ordre des motifs est porteur : `classes/42*` engloberait
     * `classes/42/etudiants`.
     *
     * @param  array<string, mixed>  $enveloppe
     */
    private function fakeKlassci(array $enveloppe, int $statut = 200): void
    {
        Http::fake([
            self::BASE_URL.'/classes/'.self::CLASSE_ID.'/etudiants*' => Http::response(
                ['success' => false, 'message' => 'Accès non autorisé à cette classe'],
                403,
            ),
            self::BASE_URL.'/classes/'.self::CLASSE_ID.'*' => Http::response($enveloppe, $statut),
            '*' => Http::response(['success' => true, 'data' => []]),
        ]);
    }

    private function appeler(?User $compte = null): TestResponse
    {
        return $this->asTenant($compte ?? $this->enseignant)->getJson(self::ROUTE);
    }

    public function test_le_roster_vient_de_l_enveloppe_avec_le_filtre_permissif(): void
    {
        $this->fakeKlassci(['success' => true, 'data' => [
            'classe' => ['id' => self::CLASSE_ID],
            'etudiants' => [
                ['id' => 1, 'nom_complet' => 'DOE Ana', 'statut' => 'actif'],
                ['id' => 2, 'nom_complet' => 'SMITH Bo', 'statut' => 'inactif'],
                ['id' => 3, 'nom_complet' => 'FOO Cy'],
                ['id' => 4, 'nom_complet' => 'BAR Di', 'statut' => 'actif'],
            ],
        ]]);

        $reponse = $this->appeler()
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 3);

        self::assertSame([1, 3, 4], array_column($reponse->json('data.etudiants'), 'id'));
        Http::assertNotSent(
            static fn (Request $requete): bool => str_contains($requete->url(), '/classes/'.self::CLASSE_ID.'/etudiants'),
        );
    }

    public function test_un_bloc_etudiants_present_et_vide_est_une_vraie_classe_vide(): void
    {
        $this->fakeKlassci(['success' => true, 'data' => [
            'classe' => ['id' => self::CLASSE_ID],
            'etudiants' => [],
        ]]);

        $this->appeler()
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => ['etudiants' => [], 'total' => 0]]);
    }

    public function test_un_200_sans_donnees_n_est_jamais_rendu_comme_une_classe_vide(): void
    {
        $this->fakeKlassci(['success' => false, 'message' => 'Classe indisponible']);

        $this->appeler()
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertExactJson(['success' => false, 'message' => KlassciUnavailableException::CLIENT_MESSAGE]);
    }

    public function test_une_enveloppe_sans_bloc_etudiants_n_est_pas_une_classe_vide(): void
    {
        $this->fakeKlassci(['success' => true, 'data' => ['classe' => ['id' => self::CLASSE_ID]]]);

        $this->appeler()
            ->assertStatus(503)
            ->assertExactJson(['success' => false, 'message' => KlassciUnavailableException::CLIENT_MESSAGE]);
    }

    public function test_un_refus_de_l_enveloppe_reste_un_403_et_non_un_500(): void
    {
        $this->fakeKlassci(['success' => false, 'message' => 'refus amont'], 403);

        $this->appeler()
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Accès refusé par KLASSCI pour ce compte.');
    }

    public function test_une_panne_amont_devient_un_503_et_non_un_500(): void
    {
        $this->fakeKlassci(['success' => false, 'message' => 'panne'], 500);

        $this->appeler()
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', KlassciUnavailableException::CLIENT_MESSAGE);
    }

    public function test_un_compte_d_ecole_klassci_sans_jeton_recoit_le_401_motive(): void
    {
        Http::fake();
        $sansJeton = User::factory()->teacher()->for($this->ecole)->create(['klassci_token' => null]);

        $this->appeler($sansJeton)
            ->assertUnauthorized()
            ->assertExactJson(MissingKlassciTokenException::payload());
        Http::assertNothingSent();
    }

    public function test_un_compte_d_ecole_autonome_recoit_le_401_motive(): void
    {
        Http::fake();
        $autonome = Institution::factory()->standalone()->create();
        $enseignant = User::factory()->teacher()->create([
            'institution_id' => $autonome->id,
            'klassci_id' => null,
            'klassci_token' => null,
        ]);

        $this->appeler($enseignant)
            ->assertUnauthorized()
            ->assertExactJson(MissingKlassciTokenException::payload());
        Http::assertNothingSent();
    }

    public function test_sans_authentification_la_route_rend_401(): void
    {
        $this->getJson(self::ROUTE)->assertUnauthorized();
    }
}
