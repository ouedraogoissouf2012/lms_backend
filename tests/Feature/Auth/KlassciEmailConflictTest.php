<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Exceptions\KlassciAccountConflictException;
use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use App\Services\Klassci\Auth\KlassciUserSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conflit d'email au sync KLASSCI (incident 2026-06-20).
 *
 * Contexte : email = un compte à vie côté KLASSCI. Un conflit (l'email confirmé
 * par KLASSCI pour le compte qui se connecte est déjà détenu localement par un
 * AUTRE compte de la même institution) est donc TOUJOURS une anomalie de données.
 *
 * Comportement attendu (production-grade) :
 *   - détection serveur AVANT l'écriture (pas via la contrainte DB) ;
 *   - exception métier dédiée → 409 (jamais 500, jamais d'écrasement silencieux
 *     de l'autre compte) ;
 *   - l'autre compte n'est PAS modifié (intégrité préservée).
 *
 * @see app/Services/Klassci/Auth/KlassciUserSynchronizer.php
 * @see app/Exceptions/KlassciAccountConflictException.php
 */
final class KlassciEmailConflictTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        // Neutralise les appels HTTP sortants (sync des classes étudiantes).
        Http::fake(['*' => Http::response(['data' => []], 200)]);
    }

    private function synchronizer(): KlassciUserSynchronizer
    {
        return app(KlassciUserSynchronizer::class);
    }

    public function test_email_owned_by_another_account_raises_conflict_not_db_error(): void
    {
        // user A : klassci_id=3, détient l'email "marcel@school.edu".
        $userA = User::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 3,
            'email' => 'marcel@school.edu',
            'role' => 'etudiant',
        ]);

        // user B : klassci_id=7, autre email.
        $userB = User::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 7,
            'email' => 'ken@school.edu',
            'role' => 'etudiant',
        ]);

        // KLASSCI confirme : klassci_id=7 a pour email "marcel@school.edu"
        // (déjà détenu par userA) → anomalie.
        $klassciUser = [
            'id' => 7,
            'nom' => 'MARCEL OUEDRAOGO',
            'email' => 'marcel@school.edu',
            'role' => 'etudiant',
        ];

        $this->expectException(KlassciAccountConflictException::class);

        try {
            $this->synchronizer()->sync(
                $klassciUser,
                'fake-token',
                'https://school-a.klassci.test',
                $this->institution,
            );
        } finally {
            // L'autre compte (userA) ne doit JAMAIS être modifié par le conflit.
            $this->assertSame('marcel@school.edu', $userA->fresh()->email);
            $this->assertSame(3, $userA->fresh()->klassci_id);
            // userB garde son email d'origine (rollback transactionnel).
            $this->assertSame('ken@school.edu', $userB->fresh()->email);
        }
    }

    public function test_normal_sync_without_conflict_succeeds(): void
    {
        $user = User::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 7,
            'email' => 'old@school.edu',
            'role' => 'etudiant',
        ]);

        // Nouvel email, libre dans l'institution → sync normal.
        $synced = $this->synchronizer()->sync(
            ['id' => 7, 'nom' => 'KEN', 'email' => 'ken.new@school.edu', 'role' => 'etudiant'],
            'fake-token',
            'https://school-a.klassci.test',
            $this->institution,
        );

        $this->assertSame('ken.new@school.edu', $synced->fresh()->email);
        $this->assertSame($user->id, $synced->id);
    }

    public function test_login_returns_409_on_account_conflict_not_500(): void
    {
        // Flow login : tenant trouvé + auth KLASSCI OK, mais le sync détecte un
        // conflit d'email → le login doit répondre 409 (pas 500).
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldReceive('findMatchingTenants')->andReturn([
                ['code' => 'school-a', 'api_base_url' => 'https://school-a.klassci.test'],
            ]);
        });
        $this->mock(KlassciAuthClient::class, function ($mock): void {
            $mock->shouldReceive('attemptLogin')->andReturn([
                'data' => ['user' => ['id' => 7, 'email' => 'marcel@school.edu'], 'token' => 'k-token'],
                'meta' => [],
            ]);
        });
        $this->mock(KlassciUserSynchronizer::class, function ($mock): void {
            $mock->shouldReceive('sync')->andThrow(new KlassciAccountConflictException('conflit'));
        });

        $response = $this->postJson('/api/auth/login', [
            'username' => 'marcel.ouedraogo',
            'password' => 'motdepassevalide',
        ]);

        $response->assertStatus(409)->assertJsonPath('success', false);
    }
}
