<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Spec redis-runtime (#374, Requirement 5) — throttle de l'écriture
 * `last_used_at` : Sanctum (Guard::updateLastUsedAt) fait un
 * `forceFill(['last_used_at' => now()])->save()` à CHAQUE requête
 * authentifiée. L'override de save() ne doit persister cette écriture
 * isolée que si la valeur précédente date d'au moins 5 minutes.
 */
final class PersonalAccessTokenLastUsedThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createTokenWithLastUsedAt(?Carbon $lastUsedAt): PersonalAccessToken
    {
        $user = User::factory()->create();
        $user->createToken('test-token');

        /** @var PersonalAccessToken $token */
        $token = PersonalAccessToken::query()->latest('id')->firstOrFail();
        // Écriture directe en base pour fixer l'état initial sans passer
        // par l'override save() qu'on teste.
        PersonalAccessToken::query()->whereKey($token->getKey())
            ->update(['last_used_at' => $lastUsedAt]);

        return $token->fresh() ?? $token;
    }

    public function test_save_skips_write_when_last_used_less_than_five_minutes_ago(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        $recent = now()->subMinutes(2);
        $token = $this->createTokenWithLastUsedAt($recent);

        $result = $token->forceFill(['last_used_at' => now()])->save();

        self::assertTrue($result, 'save() throttlé doit retourner true (contrat Sanctum inchangé).');
        self::assertTrue(
            $token->fresh()?->last_used_at?->equalTo($recent),
            'À moins de 5 minutes, l\'écriture isolée de last_used_at ne doit PAS être persistée.'
        );
    }

    public function test_save_writes_when_last_used_at_least_five_minutes_ago(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        $stale = now()->subMinutes(5)->subSecond();
        $token = $this->createTokenWithLastUsedAt($stale);

        $token->forceFill(['last_used_at' => now()])->save();

        self::assertTrue(
            $token->fresh()?->last_used_at?->equalTo(now()),
            'À 5 minutes ou plus, la nouvelle valeur de last_used_at doit être persistée.'
        );
    }

    public function test_save_writes_when_last_used_at_was_never_set(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        $token = $this->createTokenWithLastUsedAt(null);

        $token->forceFill(['last_used_at' => now()])->save();

        self::assertTrue(
            $token->fresh()?->last_used_at?->equalTo(now()),
            'Un last_used_at originellement null (token jamais utilisé) doit toujours être persisté.'
        );
    }

    public function test_save_is_never_throttled_on_token_creation(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        $user = User::factory()->create();

        $newToken = $user->createToken('fresh-token');
        /** @var PersonalAccessToken $model */
        $model = $newToken->accessToken;
        $model->forceFill(['last_used_at' => now()]);

        self::assertTrue($model->save());
        self::assertTrue(
            $model->fresh()?->last_used_at?->equalTo(now()),
            'La création/premier usage d\'un token doit toujours persister son last_used_at.'
        );
    }

    public function test_save_is_not_throttled_when_another_field_changes_too(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');
        $recent = now()->subMinutes(2);
        $token = $this->createTokenWithLastUsedAt($recent);

        // Un save() qui modifie AUSSI un autre champ ne doit jamais être
        // court-circuité : le throttle ne cible que l'écriture isolée du Guard.
        $token->forceFill(['last_used_at' => now(), 'name' => 'renamed'])->save();

        $fresh = $token->fresh();
        self::assertSame('renamed', $fresh?->name);
        self::assertTrue(
            $fresh?->last_used_at?->equalTo(now()),
            'Écriture multi-champs : last_used_at doit être persisté avec le reste.'
        );
    }
}
