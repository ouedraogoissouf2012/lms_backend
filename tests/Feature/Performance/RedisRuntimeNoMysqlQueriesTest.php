<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\Institution;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Spec redis-runtime (#374, Requirement 8) — preuve automatisée de l'objectif
 * de réduction de charge : en runtime Redis, un GET authentifié réel n'émet
 * AUCUNE requête SQL vers les tables `cache`, `sessions` et `jobs`.
 *
 * Authentification par VRAI bearer token (pas Sanctum::actingAs) pour
 * exercer le chemin Guard complet : le lookup `personal_access_tokens` est
 * une requête métier légitime (hors des 3 tables mesurées), et le fixture
 * pose un `last_used_at` récent pour que l'UPDATE systématique de Sanctum
 * soit neutralisé par le throttle (Requirement 5) — la mesure ne dépend
 * ainsi d'aucun bruit d'écriture du Guard.
 *
 * Statut : validé en local/CI uniquement, non validé en production —
 * bloqué par #367 (Requirement 9).
 */
final class RedisRuntimeNoMysqlQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection()->ping();
        } catch (\Throwable) {
            // Jamais un échec silencieux (spec) : skip explicite quand aucun
            // Redis n'est joignable (poste dev sans Redis). La jambe CI
            // `redis` exécute ce test pour de vrai contre redis:7-alpine.
            $this->markTestSkipped('Redis non joignable dans cet environnement — test exécuté par la jambe CI `redis`.');
        }

        config()->set('cache.default', 'redis');
        config()->set('session.driver', 'redis');

        $this->disableKlassciMiddleware();
    }

    public function test_authenticated_get_endpoint_hits_no_cache_sessions_or_jobs_tables(): void
    {
        $institution = Institution::factory()->create(['slug' => 'perf-inst']);
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
        ]);

        $plainToken = $user->createToken('perf-test')->plainTextToken;
        // last_used_at récent : l'UPDATE systématique de Sanctum est neutralisé
        // par le throttle (Requirement 5), la mesure reste propre.
        PersonalAccessToken::query()->latest('id')->firstOrFail()
            ->forceFill(['last_used_at' => now()->subSeconds(30)])->save();

        $this->assertNoQueriesAgainstTables(['cache', 'sessions', 'jobs'], function () use ($plainToken): void {
            $response = $this->withHeader('Authorization', "Bearer {$plainToken}")
                ->withHeader('X-Institution', 'perf-inst')
                ->getJson('/api/lms/seances/upcoming');

            $response->assertStatus(200);
        });
    }
}
