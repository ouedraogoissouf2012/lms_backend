<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SupradminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #11 — SupradminSeeder doit créer le compte initial UNIQUEMENT à
 * partir des variables d'environnement, JAMAIS d'un mot de passe hardcodé.
 *
 * Bug historique : un seeder précédent avait `'password' => Hash::make('Klassci@2026!')`
 * en dur dans le code source → le mot de passe initial était public via git.
 * OWASP A04:2021 Insecure Design (default credentials).
 *
 * Fix architectural : SupradminSeeder lit `config('supradmin.email')` et
 * `config('supradmin.password')` (qui pointent sur env vars). Si l'une
 * manque, le seeder throw `RuntimeException` (fail-secure) — il refuse
 * de créer un compte avec une valeur par défaut. Cette construction rend
 * MATÉRIELLEMENT impossible un mot de passe hardcodé.
 *
 * Cette suite de tests prouve les deux invariants (fail-secure + env-driven)
 * pour éviter une régression silencieuse.
 *
 * @see app/Models/Database/Seeders/SupradminSeeder.php
 * @see config/supradmin.php
 * @see PRODUCTION_STANDARDS.md §1.2 « Aucun secret en plaintext en base »
 */
class SupradminSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fail-secure : sans SUPRADMIN_EMAIL, refuser de créer le compte
     * plutôt que de tomber sur un défaut implicite.
     */
    public function test_seeder_throws_when_email_env_is_missing(): void
    {
        config([
            'supradmin.email'    => null,
            'supradmin.password' => 'a-valid-password',
        ]);

        $seeder = new SupradminSeeder();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SUPRADMIN_EMAIL.*SUPRADMIN_PASSWORD/');

        $seeder->run();
    }

    /**
     * Fail-secure : sans SUPRADMIN_PASSWORD, refuser de créer le compte.
     * C'est la garantie architecturale qu'aucun mot de passe par défaut
     * ne sera utilisé en production.
     */
    public function test_seeder_throws_when_password_env_is_missing(): void
    {
        config([
            'supradmin.email'    => 'admin@example.com',
            'supradmin.password' => null,
        ]);

        $seeder = new SupradminSeeder();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SUPRADMIN_EMAIL.*SUPRADMIN_PASSWORD/');

        $seeder->run();
    }

    /**
     * Happy path : avec les env vars configurées, le seeder crée un user
     * supradmin (institution_id = null, role = supradmin) dont le mot de
     * passe correspond EXACTEMENT à la valeur de l'env — preuve que la
     * valeur transite par config(), pas par un hardcoded fallback.
     */
    public function test_seeder_creates_supradmin_from_env_credentials(): void
    {
        $envEmail    = 'admin-' . bin2hex(random_bytes(4)) . '@example.test';
        $envPassword = 'strong-test-password-' . bin2hex(random_bytes(8));

        config([
            'supradmin.email'    => $envEmail,
            'supradmin.password' => $envPassword,
        ]);

        Artisan::call('db:seed', [
            '--class' => SupradminSeeder::class,
            '--force' => true,
        ]);

        $user = User::withoutGlobalScope('institution')
            ->where('email', $envEmail)
            ->first();

        $this->assertNotNull($user, 'Le supradmin doit être créé.');
        $this->assertSame('supradmin', $user->role);
        $this->assertNull($user->institution_id, 'Supradmin est cross-tenant (institution_id NULL).');
        $this->assertTrue(
            Hash::check($envPassword, $user->password),
            'Le mot de passe stocké doit correspondre à la valeur env, pas à un hardcode.'
        );
    }

    /**
     * Idempotence : exécuter le seeder deux fois ne doit pas créer de
     * doublon (firstOrCreate). Garantit que les rejouages CI / déploiements
     * répétés ne polluent pas la table users.
     */
    public function test_seeder_is_idempotent_on_repeated_runs(): void
    {
        $envEmail    = 'admin-' . bin2hex(random_bytes(4)) . '@example.test';
        $envPassword = 'idempotent-test-password';

        config([
            'supradmin.email'    => $envEmail,
            'supradmin.password' => $envPassword,
        ]);

        Artisan::call('db:seed', [
            '--class' => SupradminSeeder::class,
            '--force' => true,
        ]);
        Artisan::call('db:seed', [
            '--class' => SupradminSeeder::class,
            '--force' => true,
        ]);

        $count = User::withoutGlobalScope('institution')
            ->where('email', $envEmail)
            ->count();

        $this->assertSame(1, $count, 'Le seeder doit être idempotent — pas de doublons.');
    }
}
