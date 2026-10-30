<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Http\Middleware\EnsureSchoolMemberOrPlatformRoute;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * Le journal d'audit est une route PLATEFORME (décision D51) : le supradmin, sans
 * école, le lit pour toutes les écoles, tel quel, pour diagnostiquer et corriger.
 * L'exception est limitée à l'audit. Le superAdmin d'une école n'y a toujours
 * aucun accès.
 *
 * Vrai jeton porteur partout : c'est le chemin réel, celui où la porte des
 * comptes sans établissement et ResolveInstitution s'appliquent.
 *
 * @see app/Http/Controllers/API/Admin/AuditLogController.php
 * @see app/Http/Requests/ViewAuditLogRequest.php
 */
final class AuditLogPlatformReadTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_le_supradmin_sans_ecole_lit_le_journal_des_deux_volets(): void
    {
        $klassci = $this->entreeDansUneEcole(Institution::factory()->create());
        $autonome = $this->entreeDansUneEcole(Institution::factory()->standalone()->create());
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $entrees = $this->asTenant($supradmin)->getJson('/api/admin/audit-log')
            ->assertOk()
            ->json('data.data');

        $lues = collect($entrees)->keyBy('id');
        foreach ([$klassci, $autonome] as $attendue) {
            self::assertTrue($lues->has($attendue->id), "entrée absente de l'école {$attendue->institution_id}");
            self::assertSame($attendue->institution_id, $lues[$attendue->id]['institution_id']);
            self::assertSame($attendue->user_id, $lues[$attendue->id]['user']['id'] ?? null, "auteur non lu pour l'école {$attendue->institution_id}");
        }

        $filtrees = $this->asTenant($supradmin)->getJson('/api/admin/audit-log?user_id='.$autonome->user_id)
            ->assertOk()
            ->json('data.data');
        self::assertSame([$autonome->id], array_column($filtrees, 'id'));
    }

    /** @return iterable<string, array{0: bool}> */
    public static function volets(): iterable
    {
        yield 'école reliée à KLASSCI' => [false];
        yield 'école autonome' => [true];
    }

    /** Garde de non-régression : le refus vient de la requête, pas de la porte. */
    #[DataProvider('volets')]
    public function test_le_superadmin_d_une_ecole_reste_refuse(bool $autonome): void
    {
        $ecole = $autonome ? Institution::factory()->standalone()->create() : Institution::factory()->create();
        $this->entreeDansUneEcole($ecole);
        $this->entreeDansUneEcole(Institution::factory()->create());
        $superAdmin = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin']);

        $reponse = $this->asTenant($superAdmin)->getJson('/api/admin/audit-log');

        $reponse->assertForbidden()->assertJsonMissingPath('data');
        self::assertNotSame(EnsureSchoolMemberOrPlatformRoute::MESSAGE, $reponse->json('message'));
    }

    private function entreeDansUneEcole(Institution $ecole): AuditLog
    {
        $auteur = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'coordinateur']);

        return AuditLog::factory()->create([
            'institution_id' => $ecole->id,
            'user_id' => $auteur->id,
            'action' => 'update',
        ]);
    }
}
