<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `LMSSeanceDetailsController`
 * (axe #1, groupe-06 — test-first AVANT migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON exacte des réponses d'erreur 401 (token KLASSCI
 * absent) des deux endpoints, court-circuitées dans le service AVANT tout appel
 * réseau. Les 404 « Séance non trouvée » et les succès `{success, data}`
 * dépendent du walk KLASSCI complet (lookup rôle-aware) : leur intégration est
 * hors périmètre de ce refacto 1:1 (cf. `SeancesRoutingTest`). Le mapping
 * `successResponse($data)` / `errorResponse($m, 404)` est par ailleurs identique
 * à celui déjà figé pour les autres controllers de l'axe #1.
 *
 * Les 500 (clé sœur `error` à la racine) ne sont volontairement PAS migrées —
 * le trait DRY-only n'émet pas de clé `error` (singulier).
 *
 * NB : `SeancesRoutingTest::test_seance_details_unauthenticated_returns_401`
 * couvre le 401 d'`auth:sanctum` (non authentifié) ; ce fichier couvre le 401
 * applicatif distinct (authentifié mais sans `klassci_token`).
 *
 * @see app/Http/Controllers/API/LMS/LMSSeanceDetailsController.php
 */
final class LMSSeanceDetailsResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function userWithoutToken(): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_id' => 555,
            'klassci_token' => null,
        ]);
    }

    public function test_details_without_klassci_token_returns_401_envelope(): void
    {
        Sanctum::actingAs($this->userWithoutToken());

        $this->getJson('/api/lms/seances/42/details')
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ]);
    }

    public function test_participants_without_klassci_token_returns_401_envelope(): void
    {
        Sanctum::actingAs($this->userWithoutToken());

        $this->getJson('/api/lms/seances/42/participants')
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ]);
    }
}
