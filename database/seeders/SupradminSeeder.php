<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Creates the initial supradmin (platform-level admin) account.
 *
 * Credentials are loaded from environment variables via config/supradmin.php
 * to avoid committing secrets to git. If either variable is missing, the
 * seeder aborts with a clear error instead of silently using a default.
 *
 * Required .env variables:
 *   SUPRADMIN_EMAIL=admin@your-domain.tld
 *   SUPRADMIN_PASSWORD=<strong-random-password>
 *
 * @see config/supradmin.php
 * @see PRODUCTION_STANDARDS.md §1.2 "Aucun secret en plaintext en base"
 */
class SupradminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = config('supradmin.email');
        $password = config('supradmin.password');

        if (empty($email) || empty($password)) {
            throw new RuntimeException(
                'SupradminSeeder ne peut pas créer le compte : '
                .'les variables d\'environnement SUPRADMIN_EMAIL et SUPRADMIN_PASSWORD '
                .'doivent être définies dans .env. Voir config/supradmin.php.'
            );
        }

        User::withoutGlobalScope('institution')->firstOrCreate(
            ['email' => $email],
            [
                'name'           => 'Supradmin',
                'password'       => Hash::make($password),
                'role'           => 'supradmin',
                'institution_id' => null,
            ]
        );

        $this->command->info("Compte supradmin créé : {$email}");
        $this->command->warn(
            'Pensez à rotater le mot de passe immédiatement si vous êtes en production.'
        );
    }
}
