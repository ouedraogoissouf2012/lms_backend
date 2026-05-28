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
 * Integration tests for `GET /api/lms/classes/{classeId}` after extraction
 * to `LMSClassesController` (PR A of LMSDataController split).
 *
 * Validates the HTTP contract is preserved verbatim from the legacy
 * `LMSDataController::classeDetails`.
 *
 * @see app/Http/Controllers/API/LMS/LMSClassesController.php
 * @see .claude/specs/lms-data-controller-split/
 */
final class ClasseDetailsTest extends TestCase
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

        $response = $this->getJson('/api/lms/classes/42');

        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_returns_404_when_klassci_classe_not_found(): void
    {
        $user = $this->userWithKlassciToken();
        Sanctum::actingAs($user);

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) {
            $mock->shouldReceive('requestWithUserToken')
                ->once()
                ->with('fake-token-123', 'classes/42?with=filiere,niveau', 'GET')
                ->andReturn(['data' => null]);
        });

        $response = $this->getJson('/api/lms/classes/42');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_returns_classe_details_happy_path(): void
    {
        $user = $this->userWithKlassciToken();
        Sanctum::actingAs($user);

        $classeData = [
            'id' => 42,
            'nom' => 'L1 INFO',
            'filiere' => ['id' => 1, 'name' => 'Informatique'],
            'niveau' => ['id' => 2, 'name' => 'L1'],
            'nombre_places' => 30,
        ];

        $etudiants = [
            ['id' => 1, 'nom' => 'Doe', 'prenom' => 'John', 'statut' => 'actif'],
            ['id' => 2, 'nom' => 'Smith', 'prenom' => 'Jane', 'statut' => 'inactif'],
            ['id' => 3, 'nom' => 'Foo', 'prenom' => 'Bar', 'statut' => 'actif'],
        ];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($classeData, $etudiants) {
            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token-123', 'classes/42?with=filiere,niveau', 'GET')
                ->once()
                ->andReturn(['data' => $classeData]);

            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token-123', 'classes/42/etudiants', 'GET')
                ->once()
                ->andReturn(['data' => $etudiants]);

            $mock->shouldReceive('requestWithUserToken')
                ->withArgs(fn ($token, $url, $method) => str_starts_with((string) $url, 'emploi-temps?'))
                ->once()
                ->andReturn(['data' => []]);

            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token-123', 'evaluations', 'GET')
                ->once()
                ->andReturn(['data' => []]);

            $mock->shouldReceive('requestWithUserToken')
                ->withArgs(fn ($token, $url, $method) => str_starts_with((string) $url, 'matieres?'))
                ->once()
                ->andReturn(['data' => []]);
        });

        $response = $this->getJson('/api/lms/classes/42');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.classe.id', 42)
            ->assertJsonPath('data.statistiques.nombre_etudiants', 2); // strict: only `statut === 'actif'`
    }

    public function test_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/lms/classes/42');

        $response->assertStatus(401);
    }
}
