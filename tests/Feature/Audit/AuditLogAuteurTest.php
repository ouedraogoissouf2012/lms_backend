<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\ValidatesAgainstOpenApi;
use Tests\TestCase;

/**
 * L'auteur d'une entrée du journal d'audit (`GET /admin/audit-log`) est servi
 * tel qu'il était, même :
 * - supprimé depuis (suppression logique) : c'est le cas qu'on diagnostique ;
 * - d'une autre école que celle d'un tenant posé : la lecture est plateforme
 *   (D51), et aucun scope de tenant ne masque un auteur.
 *
 * Vrai jeton porteur ; chaque réponse suit `docs/openapi.yaml`.
 */
final class AuditLogAuteurTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;
    use ValidatesAgainstOpenApi;

    private const CHEMIN = '/admin/audit-log';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_un_auteur_supprime_reste_servi(): void
    {
        $ecole = Institution::factory()->create();
        $auteur = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'coordinateur', 'name' => 'Auteur parti']);
        $entree = AuditLog::factory()->create(['institution_id' => $ecole->id, 'user_id' => $auteur->id, 'action' => 'update']);
        $auteur->delete();
        self::assertSoftDeleted($auteur);

        $reponse = $this->lire(User::factory()->create(['institution_id' => null, 'role' => 'supradmin']));

        self::assertSame($auteur->id, $this->auteurDe($reponse, $entree)['id'] ?? null);
        self::assertSame('Auteur parti', $this->auteurDe($reponse, $entree)['name'] ?? null);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    /** Un tenant posé (un supradmin rattaché à une école) ne masque pas l'auteur d'une autre école. */
    public function test_un_tenant_pose_ne_masque_pas_l_auteur_d_une_autre_ecole(): void
    {
        $ecoleA = Institution::factory()->create();
        $ecoleB = Institution::factory()->standalone()->create();
        $auteurB = User::factory()->create(['institution_id' => $ecoleB->id, 'role' => 'coordinateur', 'name' => 'Auteur de B']);
        $entree = AuditLog::factory()->create(['institution_id' => $ecoleB->id, 'user_id' => $auteurB->id, 'action' => 'update']);
        $supradminRattache = User::factory()->create(['institution_id' => $ecoleA->id, 'role' => 'supradmin']);

        $reponse = $this->lire($supradminRattache);

        // Témoin : le tenant de l'école A est bien posé pour cette requête (sinon
        // le test ne prouverait rien sur le scope).
        self::assertSame($ecoleA->id, app(\App\Services\TenantManager::class)->id());
        self::assertSame('Auteur de B', $this->auteurDe($reponse, $entree)['name'] ?? null);
        $this->assertMatchesOpenApiResponse($reponse, self::CHEMIN, 'get');
    }

    private function lire(User $lecteur): TestResponse
    {
        return $this->asTenant($lecteur)->getJson('/api'.self::CHEMIN.'?per_page=50')->assertOk();
    }

    /** @return array<string, mixed> l'auteur servi pour l'entrée, ou [] */
    private function auteurDe(TestResponse $reponse, AuditLog $entree): array
    {
        $ligne = collect((array) $reponse->json('data.data'))->firstWhere('id', $entree->id);
        self::assertIsArray($ligne, "Entrée {$entree->id} absente de la réponse.");

        return is_array($ligne['user'] ?? null) ? $ligne['user'] : [];
    }
}
