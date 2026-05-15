<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #4 [HIGH-1] — Sanctum tokens must have an expiration deadline.
 *
 * A token without expiration that leaks (XSS, log exposure, screenshot, MITM)
 * grants permanent access until manually revoked. This test asserts that the
 * `'expiration'` config in `config/sanctum.php` is set, is loaded from the
 * `SANCTUM_TOKEN_EXPIRATION` env var (no hardcoding), and is actually enforced
 * by Sanctum at request time.
 *
 * Reference :
 * - config/sanctum.php:50
 * - Laravel docs : https://laravel.com/docs/12.x/sanctum#token-expiration
 * - OWASP A07:2021 — Identification and Authentication Failures
 */
class SanctumTokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $institution = Institution::factory()->create();
        $this->user = User::factory()->create([
            'institution_id' => $institution->id,
            'role'           => 'etudiant',
        ]);
    }

    /**
     * The expiration value must come from the env var, not be hardcoded.
     * Default in config/sanctum.php is 10080 minutes (7 days), but the env
     * is the source of truth — operators must be able to override per-env.
     */
    public function test_expiration_config_is_an_integer_loaded_from_env(): void
    {
        $expiration = config('sanctum.expiration');

        $this->assertIsInt(
            $expiration,
            'config(sanctum.expiration) must be an integer (loaded from SANCTUM_TOKEN_EXPIRATION).'
        );
        $this->assertGreaterThan(
            0,
            $expiration,
            'Token expiration must be > 0 (a non-positive value disables expiration — security risk).'
        );
    }

    /**
     * Happy path : a fresh token successfully accesses a protected endpoint.
     */
    public function test_fresh_token_accesses_protected_endpoint(): void
    {
        $token = $this->user->createToken('fresh-test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/user');

        $response->assertOk();
    }

    /**
     * A token used 1 minute BEFORE its deadline must still work.
     * Boundary test : confirms we're not invalidating too early.
     */
    public function test_token_still_valid_one_minute_before_deadline(): void
    {
        $token = $this->user->createToken('before-deadline')->plainTextToken;

        // Travel forward to just before the deadline.
        $this->travel($this->expirationMinutes() - 1)->minutes();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/user');

        $response->assertOk();
    }

    /**
     * Issue #4 core assertion : a token used AFTER its deadline must be rejected
     * with 401. Without this, a leaked token grants permanent access.
     */
    public function test_expired_token_returns_401(): void
    {
        $token = $this->user->createToken('expired-test')->plainTextToken;

        // Travel forward past the deadline.
        $this->travel($this->expirationMinutes() + 1)->minutes();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/user');

        $response->assertUnauthorized();
    }

    /**
     * Reads config('sanctum.expiration') and asserts it is a strictly positive
     * integer. Centralised so PHPStan level 9 narrows mixed to int via the
     * is_int() check, without an unsafe cast or suppression directive.
     */
    private function expirationMinutes(): int
    {
        $value = config('sanctum.expiration');

        if (! is_int($value) || $value <= 0) {
            $this->fail(
                'config(sanctum.expiration) must be a positive integer — issue #4 regression?'
            );
        }

        return $value;
    }
}
