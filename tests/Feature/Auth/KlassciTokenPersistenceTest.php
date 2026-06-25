<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Persistance du token KLASSCI au sync (incident « Token KLASSCI non trouvé »).
 *
 * Le synchronizer écrit l'attribut `klassci_token` (alias public ; mutateur ->
 * colonne chiffrée `klassci_token_encrypted`). Si `klassci_token` n'est pas
 * `fillable`, le mass-assignment (`User::update`/`create`) le DROP silencieusement
 * → le token n'est jamais persisté → tous les endpoints `/proxy/*` répondent 401.
 *
 * Ces tests verrouillent la persistance sur les DEUX chemins (création + update)
 * et en multi-tenant (2 institutions) — conformément à PRODUCTION_STANDARDS §1.4.
 *
 * @see app/Models/User.php ($fillable + setKlassciTokenAttribute)
 * @see app/Services/Klassci/Auth/KlassciUserSynchronizer.php (buildCommonData)
 */
final class KlassciTokenPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Neutralise les appels HTTP sortants (sync des classes étudiantes).
        Http::fake(['*' => Http::response(['data' => []], 200)]);
    }

    private function synchronizer(): KlassciUserSynchronizer
    {
        return app(KlassciUserSynchronizer::class);
    }

    public function test_klassci_token_persisted_on_new_user_creation(): void
    {
        $institution = Institution::factory()->create(['slug' => 'school-a']);

        $synced = $this->synchronizer()->sync(
            ['id' => 10, 'nom' => 'PROF NOUVEAU', 'email' => 'prof.new@school.edu', 'role' => 'enseignant'],
            'klassci-token-new',
            'https://school-a.klassci.test',
            $institution,
        );

        $this->assertSame('klassci-token-new', $synced->fresh()->klassci_token);
    }

    public function test_klassci_token_refreshed_on_existing_user_update(): void
    {
        // LE cas du bug : un utilisateur existant qui se reconnecte → chemin update().
        $institution = Institution::factory()->create(['slug' => 'school-a']);
        User::factory()->create([
            'institution_id' => $institution->id,
            'klassci_id' => 22,
            'email' => 'prof.existant@school.edu',
            'role' => 'enseignant',
        ]);

        $synced = $this->synchronizer()->sync(
            ['id' => 22, 'nom' => 'PROF EXISTANT', 'email' => 'prof.existant@school.edu', 'role' => 'enseignant'],
            'klassci-token-refreshed',
            'https://school-a.klassci.test',
            $institution,
        );

        $this->assertSame('klassci-token-refreshed', $synced->fresh()->klassci_token);
    }

    public function test_klassci_token_persisted_per_institution(): void
    {
        $schoolA = Institution::factory()->create(['slug' => 'school-a']);
        $schoolB = Institution::factory()->create(['slug' => 'school-b']);

        $a = $this->synchronizer()->sync(
            ['id' => 1, 'nom' => 'USER A', 'email' => 'u@a.edu', 'role' => 'enseignant'],
            'token-A',
            'https://school-a.klassci.test',
            $schoolA,
        );
        $b = $this->synchronizer()->sync(
            ['id' => 1, 'nom' => 'USER B', 'email' => 'u@b.edu', 'role' => 'enseignant'],
            'token-B',
            'https://school-b.klassci.test',
            $schoolB,
        );

        $this->assertSame('token-A', $a->fresh()->klassci_token);
        $this->assertSame('token-B', $b->fresh()->klassci_token);
    }
}
