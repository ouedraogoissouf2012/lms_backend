<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\TenantAwareTokenRepository;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * #714 — deux écoles, même adresse, jetons étanches.
 */
final class TenantPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_without_institution_header_is_rejected(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'a@b.test'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'En-tête X-Institution requis');
    }

    public function test_user_without_email_gets_an_explicit_error(): void
    {
        $institution = Institution::factory()->create(['slug' => 'ecole-vide']);
        User::factory()->create([
            'institution_id' => $institution->id,
            'email' => '',
        ]);

        $this->withHeader('X-Institution', $institution->slug)
            ->postJson('/api/auth/forgot-password', ['email' => ''])
            ->assertStatus(422);
    }

    public function test_token_from_school_a_does_not_reset_school_b(): void
    {
        Notification::fake();
        [$schoolA, $schoolB, $userA] = $this->twoSchoolsSameEmail();

        app(TenantManager::class)->set($schoolA);
        $tokenA = app(TenantAwareTokenRepository::class)->create($userA);

        $this->withHeader('X-Institution', $schoolB->slug)
            ->postJson('/api/auth/reset-password', [
                'email' => 'same@reset.test',
                'token' => $tokenA,
                'password' => 'Nouveau123',
                'password_confirmation' => 'Nouveau123',
            ])
            ->assertStatus(422);

        $userA->refresh();
        $this->assertTrue(Hash::check('password', $userA->password));
    }

    public function test_two_schools_reset_independently(): void
    {
        Notification::fake();
        [$schoolA, $schoolB, $userA, $userB] = $this->twoSchoolsSameEmail();

        app(TenantManager::class)->set($schoolA);
        $tokenA = app(TenantAwareTokenRepository::class)->create($userA);
        app(TenantManager::class)->set($schoolB);
        $tokenB = app(TenantAwareTokenRepository::class)->create($userB);

        $this->withHeader('X-Institution', $schoolA->slug)
            ->postJson('/api/auth/reset-password', [
                'email' => 'same@reset.test',
                'token' => $tokenA,
                'password' => 'Alpha9999',
                'password_confirmation' => 'Alpha9999',
            ])
            ->assertOk();

        $this->withHeader('X-Institution', $schoolB->slug)
            ->postJson('/api/auth/reset-password', [
                'email' => 'same@reset.test',
                'token' => $tokenB,
                'password' => 'Beta9999x',
                'password_confirmation' => 'Beta9999x',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Alpha9999', $userA->fresh()->password));
        $this->assertTrue(Hash::check('Beta9999x', $userB->fresh()->password));
    }

    /**
     * @return array{0: Institution, 1: Institution, 2: User, 3: User}
     */
    private function twoSchoolsSameEmail(): array
    {
        $schoolA = Institution::factory()->create(['slug' => 'ecole-a']);
        $schoolB = Institution::factory()->create(['slug' => 'ecole-b']);
        $userA = User::factory()->create([
            'institution_id' => $schoolA->id,
            'email' => 'same@reset.test',
        ]);
        $userB = User::factory()->create([
            'institution_id' => $schoolB->id,
            'email' => 'same@reset.test',
        ]);

        return [$schoolA, $schoolB, $userA, $userB];
    }
}
