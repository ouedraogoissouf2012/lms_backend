<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #703 — migrate:fresh ne doit pas détruire la base locale sans opt-in.
 */
final class DestructiveCommandsGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::prohibitDestructiveCommands(false);
        parent::tearDown();
    }

    public function test_fresh_is_blocked_when_destructive_commands_are_prohibited(): void
    {
        DB::prohibitDestructiveCommands(true);

        $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
    }

    public function test_provider_reads_config_not_env(): void
    {
        $src = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        self::assertStringContainsString("config('database.allow_destructive'", $src);
        self::assertStringNotContainsString("env('DB_ALLOW_DESTRUCTIVE'", $src);
    }

    public function test_guard_uses_config_when_env_would_lie(): void
    {
        config(['database.allow_destructive' => true]);
        putenv('DB_ALLOW_DESTRUCTIVE=false');
        $_ENV['DB_ALLOW_DESTRUCTIVE'] = 'false';

        try {
            self::assertFalse((bool) env('DB_ALLOW_DESTRUCTIVE', false));
            self::assertTrue((bool) config('database.allow_destructive'));
        } finally {
            putenv('DB_ALLOW_DESTRUCTIVE');
            unset($_ENV['DB_ALLOW_DESTRUCTIVE']);
        }
    }
}
