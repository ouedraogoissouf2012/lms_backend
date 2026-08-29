<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests pour le throttle du groupe /api/search/* — issue #548.
 *
 * RateLimiter::clear() en setUp : la clé de rate limit est partagée au niveau
 * process PHPUnit (par user id), pas isolée par test — sans ce clear, un test
 * précédent qui a déjà consommé le quota ferait échouer celui-ci de façon non
 * déterministe (leçon tirée du bug de fuite d'environnement rencontré sur #545 :
 * tout état partagé entre tests doit être explicitement réinitialisé).
 */
final class SearchThrottleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $institution = Institution::factory()->create();

        $this->user = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);

        RateLimiter::clear('search:'.$this->user->id);
    }

    public function test_search_group_is_throttled_after_30_requests_per_minute(): void
    {
        Sanctum::actingAs($this->user);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/search?query=math')->assertStatus(200);
        }

        $this->getJson('/api/search?query=math')->assertStatus(429);
    }
}
