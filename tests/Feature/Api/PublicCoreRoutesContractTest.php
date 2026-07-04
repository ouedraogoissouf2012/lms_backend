<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Caractérisation du contrat JSON des routes closures de `routes/api/core.php`
 * AVANT leur extraction vers controllers/services (issue #375).
 *
 * Golden master : ces tests capturent le JSON exact vu par le frontend
 * (`lms-frontend/src/stores/auth.js` consomme `/institutions/active`) et
 * doivent rester verts à l'identique après le refactoring — tout écart de
 * contrat est une régression, pas une amélioration (précédent : axe #1
 * « DRY-only », PR #392 « verbatim »).
 */
final class PublicCoreRoutesContractTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // GET /api/ping
    // ------------------------------------------------------------------

    public function test_ping_repond_200_avec_les_cles_racine_historiques(): void
    {
        $response = $this->getJson('/api/ping');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'LMS KLASSCI Backend API is running!',
                'version' => '1.0.0',
            ])
            // Contrat historique : timestamp/version à la RACINE (pas sous
            // `data`) — toute migration vers l'enveloppe canonique casserait
            // les moniteurs externes qui pingueraient ce endpoint.
            ->assertJsonStructure(['success', 'message', 'timestamp', 'version']);
    }

    // ------------------------------------------------------------------
    // GET /api/institutions/active (public — sélecteur de la page login)
    // ------------------------------------------------------------------

    public function test_institutions_actives_liste_les_deux_tenants_triee_par_nom_avec_les_seules_cles_publiques(): void
    {
        // Cross-tenant PAR DESIGN (§1.3 : les 2 institutions apparaissent) :
        // c'est l'annuaire public du sélecteur de login, avant toute auth.
        Institution::factory()->create([
            'name' => 'Beta Institut',
            'slug' => 'beta',
            'is_active' => true,
            'logo_url' => 'https://cdn.example/beta.png',
            'primary_color' => '#00ff00',
        ]);
        Institution::factory()->create([
            'name' => 'Alpha École',
            'slug' => 'alpha',
            'is_active' => true,
            'logo_url' => null,
            'primary_color' => '#ff0000',
        ]);

        $response = $this->getJson('/api/institutions/active');

        $response->assertOk()->assertExactJson([
            'success' => true,
            'data' => [
                [
                    'slug' => 'alpha',
                    'name' => 'Alpha École',
                    'logo_url' => null,
                    'primary_color' => '#ff0000',
                ],
                [
                    'slug' => 'beta',
                    'name' => 'Beta Institut',
                    'logo_url' => 'https://cdn.example/beta.png',
                    'primary_color' => '#00ff00',
                ],
            ],
        ]);
    }

    public function test_institutions_actives_exclut_les_institutions_inactives(): void
    {
        Institution::factory()->create(['slug' => 'active-one', 'is_active' => true]);
        Institution::factory()->create(['slug' => 'dormante', 'is_active' => false]);

        $response = $this->getJson('/api/institutions/active');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'active-one');
    }

    public function test_institutions_actives_liste_vide_conserve_la_cle_data(): void
    {
        // `data: []` doit rester présent (le frontend itère dessus sans garde).
        $this->getJson('/api/institutions/active')
            ->assertOk()
            ->assertExactJson(['success' => true, 'data' => []]);
    }

    // ------------------------------------------------------------------
    // GET /api/institution/current (public — résolu via header X-Institution)
    // ------------------------------------------------------------------

    public function test_institution_courante_retourne_le_descripteur_du_tenant_resolu(): void
    {
        Institution::factory()->create([
            'name' => 'Alpha École',
            'slug' => 'alpha',
            'is_active' => true,
            'logo_url' => 'https://cdn.example/alpha.png',
            'primary_color' => '#ff0000',
        ]);

        $response = $this->getJson('/api/institution/current', ['X-Institution' => 'alpha']);

        $response->assertOk()->assertExactJson([
            'success' => true,
            'data' => [
                'slug' => 'alpha',
                'name' => 'Alpha École',
                'logo_url' => 'https://cdn.example/alpha.png',
                'primary_color' => '#ff0000',
            ],
        ]);
    }

    public function test_institution_courante_sans_tenant_resolu_retourne_400_avec_le_message_historique(): void
    {
        $response = $this->getJson('/api/institution/current');

        $response->assertStatus(400)->assertExactJson([
            'success' => false,
            'message' => 'Aucune institution résolue',
        ]);
    }

    public function test_institution_courante_slug_inconnu_est_rejete_400_par_le_middleware(): void
    {
        // Le 400 « Institution non trouvée ou inactive » vient de
        // ResolveInstitution, PAS de la route — caractérisé pour figer la
        // frontière middleware/controller pendant le refactoring.
        $this->getJson('/api/institution/current', ['X-Institution' => 'fantome'])
            ->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    // ------------------------------------------------------------------
    // GET /api/user (protégée auth:sanctum)
    // ------------------------------------------------------------------

    public function test_user_sans_token_retourne_401(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_user_authentifie_recoit_son_profil_sous_data_sans_champs_caches(): void
    {
        $institution = Institution::factory()->create(['is_active' => true]);
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);
        Sanctum::actingAs($user, ['lms:access']);

        $response = $this->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            // $hidden du modèle : jamais sérialisés (§1.2).
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.klassci_token');
    }
}
