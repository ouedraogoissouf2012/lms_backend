<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `LMSSeancesListController`
 * (axe #1, groupe-06 — test-first AVANT migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON exacte des réponses MIGRABLES (enveloppes portant
 * la clé `success`) afin qu'une migration vers `successResponse`/`errorResponse`
 * ne change RIEN côté client.
 *
 * ## Périmètre déterministe (sans HTTP KLASSCI réel)
 *
 * Les réponses 401 (token absent) court-circuitent dans le service AVANT tout
 * appel réseau : elles sont assertées en `assertExactJson`. Les branches
 * `classe_missing` (404), `empty_matieres` (200) et le succès vide de
 * `my-teaching` court-circuitent juste après le 1er appel `me/dashboard` /
 * `me/teacher-dashboard`, mocké ici via un double de `KlassciProxyService`.
 *
 * Les succès avec payload KLASSCI complet (walk dashboard → matières → séances)
 * relèvent de l'intégration KLASSCI, hors périmètre de ce refacto 1:1 (cf.
 * `SeancesRoutingTest`). Leur enveloppe `{success, data}` est néanmoins figée
 * ici par le cas `my-teaching` vide (même mapping `successResponse($data)`).
 *
 * La réponse `upcoming` (qui expose une clé sœur `meta` à la racine) et les
 * 500 (qui exposent une clé sœur `error`) ne sont volontairement PAS migrées
 * (le trait, DRY-only, n'émet `meta` que non-vide et n'émet pas `error`) — voir
 * le commentaire de garde dans le controller.
 *
 * @see app/Http/Controllers/API/LMS/LMSSeancesListController.php
 */
final class LMSSeancesListResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    private function user(string $role = 'enseignant', ?string $token = 'fake-token'): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => $role,
            'klassci_id' => 555,
            'klassci_token' => $token,
        ]);
    }

    // ───────────────────────── 401 : token KLASSCI absent ─────────────────────────

    public function test_upcoming_without_klassci_token_returns_401_envelope(): void
    {
        Sanctum::actingAs($this->user(token: null));

        $this->getJson('/api/lms/seances/upcoming')
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => 'Token KLASSCI non trouvé']);
    }

    public function test_my_teaching_without_klassci_token_returns_401_envelope(): void
    {
        Sanctum::actingAs($this->user(token: null));

        $this->getJson('/api/lms/seances/my-teaching')
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => 'Token KLASSCI non trouvé']);
    }

    public function test_my_classes_without_klassci_token_returns_401_envelope(): void
    {
        Sanctum::actingAs($this->user('etudiant', token: null));

        $this->getJson('/api/lms/seances/my-classes')
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => 'Token KLASSCI non trouvé']);
    }

    // ───────────────────────── my-teaching : succès enveloppe {success, data} ─────────────────────────

    public function test_my_teaching_success_returns_data_envelope_without_message(): void
    {
        Sanctum::actingAs($this->user('enseignant'));

        // Dashboard enseignant sans matière → collection vide → succès `data: []`.
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'me/teacher-dashboard', 'GET')
                ->andReturn(['data' => ['matieres' => []]]);
            $mock->shouldReceive('fetchManyMatieresDetails')->andReturn([]);
            $mock->shouldReceive('fetchManyClassesDetails')->andReturn([]);
        });

        $response = $this->getJson('/api/lms/seances/my-teaching');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame([], $body['data']);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────────── my-classes : branches métier ─────────────────────────

    public function test_my_classes_without_resolvable_class_returns_404_envelope(): void
    {
        Sanctum::actingAs($this->user('etudiant'));

        // Dashboard étudiant sans classe résoluble → 404 `classe_missing`.
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'me/dashboard', 'GET')
                ->andReturn(['data' => ['classe' => ['id' => null]]]);
        });

        $this->getJson('/api/lms/seances/my-classes')
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Aucune classe trouvée pour cet étudiant']);
    }

    public function test_my_classes_without_matieres_returns_200_empty_data_with_message(): void
    {
        Sanctum::actingAs($this->user('etudiant'));

        // Classe résoluble mais aucun cours → 200 `empty_matieres` (data vide + message).
        $this->mock(KlassciProxyService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'me/dashboard', 'GET')
                ->andReturn(['data' => ['classe' => ['id' => 123], 'cours' => []]]);
        });

        $this->getJson('/api/lms/seances/my-classes')
            ->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'data' => [],
                'message' => 'Aucune matière trouvée pour cet étudiant',
            ]);
    }
}
