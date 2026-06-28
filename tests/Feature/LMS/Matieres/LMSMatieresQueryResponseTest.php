<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Matieres;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Caractérisation du contrat de réponse de `LMSMatieresQueryController`
 * (axe #1, groupe-07 — issue #305).
 *
 * Les services métier (`MatiereDetailsQueryService`, `MyMatieresQueryService`)
 * sont `final` (non mockables) et orchestrent des fetchers KLASSCI réels : on
 * caractérise ici les réponses **401** déterministes (token absent → le service
 * lève `RuntimeException` AVANT tout I/O réseau). La migration des succès
 * (`successResponse($data)`) est couverte transitivement par les tests
 * `LMSMatieresAdminResponseTest` / `LMSEnseignantsResponseTest` (même fabrique)
 * et par les tests unitaires du trait.
 *
 * @see app/Http/Controllers/API/LMS/LMSMatieresQueryController.php
 */
final class LMSMatieresQueryResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    private function actingAsTeacherWithoutToken(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
            'klassci_token' => null,
        ]));
    }

    public function test_matiere_details_without_klassci_token_returns_401_envelope(): void
    {
        $this->actingAsTeacherWithoutToken();

        $this->getJson('/api/lms/matieres/1')
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ]);
    }

    public function test_my_matieres_without_klassci_token_returns_401_envelope(): void
    {
        $this->actingAsTeacherWithoutToken();

        $this->getJson('/api/lms/teacher/my-matieres')
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ]);
    }
}
