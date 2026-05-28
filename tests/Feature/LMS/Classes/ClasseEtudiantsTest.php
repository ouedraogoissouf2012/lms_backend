<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Classes;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Integration tests for `GET /api/lms/classes/{classeId}/etudiants` after
 * extraction to `LMSClassesController` (PR A of LMSDataController split).
 *
 * Note: filter semantics are intentionally permissive here
 * (`!isset($statut) || $statut === 'actif'`) — different from `classeDetails`
 * which uses strict `isset && === 'actif'`. Both behaviors preserved verbatim
 * from the original LMSDataController (cf. controller comment L262).
 *
 * @see app/Http/Controllers/API/LMS/LMSClassesController.php
 * @see .claude/specs/lms-data-controller-split/
 */
final class ClasseEtudiantsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function userWithKlassciToken(string $token = 'fake-token-123', string $role = 'enseignant'): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'klassci_token' => $token,
        ]);
    }

    public function test_returns_401_when_user_has_no_klassci_token(): void
    {
        $user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_token' => null,
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/lms/classes/42/etudiants');

        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_returns_etudiants_with_permissive_active_filter(): void
    {
        $user = $this->userWithKlassciToken();
        Sanctum::actingAs($user);

        $etudiants = [
            ['id' => 1, 'nom' => 'Doe', 'statut' => 'actif'],
            ['id' => 2, 'nom' => 'Smith', 'statut' => 'inactif'],   // excluded
            ['id' => 3, 'nom' => 'Foo'],                              // INCLUDED (no statut field = legacy)
            ['id' => 4, 'nom' => 'Bar', 'statut' => 'actif'],
        ];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($etudiants) {
            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token-123', 'classes/42/etudiants', 'GET')
                ->once()
                ->andReturn(['data' => $etudiants]);
        });

        $response = $this->getJson('/api/lms/classes/42/etudiants');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', 3); // permissive: includes legacy row
    }

    public function test_returns_empty_list_when_no_etudiants(): void
    {
        $user = $this->userWithKlassciToken();
        Sanctum::actingAs($user);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token-123', 'classes/42/etudiants', 'GET')
                ->once()
                ->andReturn(['data' => []]);
        });

        $response = $this->getJson('/api/lms/classes/42/etudiants');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.etudiants', []);
    }

    public function test_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/lms/classes/42/etudiants');

        $response->assertStatus(401);
    }
}
