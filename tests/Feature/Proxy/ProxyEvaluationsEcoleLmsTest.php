<?php

declare(strict_types=1);

namespace Tests\Feature\Proxy;

use App\Enums\InstitutionMode;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Support\Catalogue\SourceLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\BuildsEcoleIndependante;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * `GET /api/proxy/evaluations` (la programmation KLASSCI des évaluations) dans les
 * deux types d'école.
 *
 * Avant : le contrôleur exigeait le jeton KLASSCI personnel AVANT tout. Dans une école
 * créée depuis le LMS, l'enseignant recevait 401 `klassci_token_missing`, et l'écran
 * affichait « Aucun accès KLASSCI » (relevé par le premier E2E de la nuit).
 *
 * Une école créée depuis le LMS n'a pas de programmation KLASSCI : la liste est VIDE,
 * sans 401 ni appel à KLASSCI, et dit son origine (`meta.source`, D172). L'école KLASSCI
 * est inchangée.
 *
 * Vraie route, vrai jeton (`asTenant`).
 */
final class ProxyEvaluationsEcoleLmsTest extends TestCase
{
    use ActsAsTenantUser;
    use BuildsEcoleIndependante;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    public function test_ecole_du_lms_la_liste_est_vide_sans_401_ni_appel_klassci(): void
    {
        $sansJeton = $this->membre($this->ecole(), 'enseignant');
        $avecJeton = $this->membre($this->ecole(), 'enseignant');
        $avecJeton->forceFill(['klassci_token' => 'jeton-perime'])->save();
        $this->aucunAppelKlassci();

        foreach ([$sansJeton, $avecJeton] as $enseignant) {
            $this->asTenant($enseignant)->getJson('/api/proxy/evaluations')
                ->assertOk()
                ->assertExactJson(['success' => true, 'data' => [], 'meta' => ['source' => SourceLocale::LMS_LOCAL]]);
        }
    }

    public function test_ecole_klassci_sans_jeton_reste_un_401(): void
    {
        $enseignant = $this->membre($this->ecole(InstitutionMode::Klassci), 'enseignant');
        $this->disableKlassciMiddleware();

        $this->asTenant($enseignant)->getJson('/api/proxy/evaluations')
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'klassci_token_missing');
    }

    public function test_ecole_klassci_avec_jeton_relaie_la_programmation_avec_ses_filtres(): void
    {
        $enseignant = $this->enseignantKlassci();
        $this->klassciProgramme();

        $this->asTenant($enseignant)->getJson('/api/proxy/evaluations?classe_id=55&statut=planifiee')
            ->assertOk()
            ->assertJsonPath('data.0.id', 900);
    }

    /** La forme locale suit la spec : fermée, marquée `meta.source` (B8, revue de Fille 1). */
    public function test_ecole_du_lms_la_reponse_suit_la_spec(): void
    {
        $this->aucunAppelKlassci();

        $reponse = $this->asTenant($this->membre($this->ecole(), 'enseignant'))->getJson('/api/proxy/evaluations')->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, '/proxy/evaluations', 'get');
    }

    /** La charge KLASSCI, relayée telle quelle, suit aussi la spec, sans la marque locale. */
    public function test_ecole_klassci_la_reponse_relayee_suit_la_spec(): void
    {
        $enseignant = $this->enseignantKlassci();
        $this->klassciProgramme();

        $reponse = $this->asTenant($enseignant)->getJson('/api/proxy/evaluations?classe_id=55&statut=planifiee')->assertOk();

        $this->assertMatchesOpenApiResponse($reponse, '/proxy/evaluations', 'get');
    }

    /** Laravel garde le contrôleur sur l'objet Route : la source se reçoit par la MÉTHODE. */
    public function test_la_meme_route_sert_une_ecole_klassci_puis_une_ecole_du_lms(): void
    {
        $enseignantKlassci = $this->enseignantKlassci();
        $enseignantLocal = $this->membre($this->ecole(), 'enseignant');
        $this->klassciProgramme();

        $this->asTenant($enseignantKlassci)->getJson('/api/proxy/evaluations?classe_id=55&statut=planifiee')
            ->assertOk()->assertJsonPath('data.0.id', 900);
        $this->asTenant($enseignantLocal)->getJson('/api/proxy/evaluations')
            ->assertOk()->assertJsonPath('meta.source', SourceLocale::LMS_LOCAL)->assertJsonPath('data', []);
    }

    private function enseignantKlassci(): User
    {
        $enseignant = $this->membre($this->ecole(InstitutionMode::Klassci), 'enseignant');
        $enseignant->forceFill(['klassci_token' => 'jeton'])->save();
        $this->disableKlassciMiddleware();

        return $enseignant;
    }

    private function klassciProgramme(): void
    {
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getEvaluations')
                ->with('jeton', ['classe_id' => '55', 'statut' => 'planifiee'])
                ->andReturn(['success' => true, 'data' => [['id' => 900, 'titre' => 'Devoir']]]);
        });
    }

    /** Aucun appel à KLASSCI : ni requête sortante, ni méthode du mandataire. */
    private function aucunAppelKlassci(): void
    {
        Http::preventStrayRequests();
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('getEvaluations');
        });
    }
}
