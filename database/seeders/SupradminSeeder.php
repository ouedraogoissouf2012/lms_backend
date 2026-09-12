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
        $email = config('supradmin.email');
        $password = config('supradmin.password');

        if (empty($email) || empty($password)) {
            throw new RuntimeException(
                'SupradminSeeder ne peut pas créer le compte : '
                .'les variables d\'environnement SUPRADMIN_EMAIL et SUPRADMIN_PASSWORD '
                .'doivent être définies dans .env. Voir config/supradmin.php.'
            );
        }

        $user = User::withoutGlobalScope('institution')->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Supradmin',
                'password' => Hash::make($password),
                'role' => 'supradmin',
                'institution_id' => null,
            ]
        );

        // #688 — ne JAMAIS annoncer une création qui n'a pas eu lieu.
        //
        // `firstOrCreate` est idempotent, et c'est voulu. Mais l'ancien message
        // disait « Compte supradmin créé » aux deux passages : au second, il
        // affirmait un fait faux. C'est exactement le symptôme rapporté — un
        // seeder qui annonce un succès sans rien faire — et il aurait suffi à
        // envoyer quelqu'un chercher la cause ailleurs pendant longtemps.
        //
        // Le mot de passe n'est PAS réappliqué sur un compte existant : ce
        // seeder crée, il ne réinitialise pas. Le dire évite la croyance
        // inverse, qui ferait chercher un mot de passe qui n'a pas changé.
        if ($user->wasRecentlyCreated) {
            $this->say('info', "Compte supradmin créé : {$email}");
            $this->say('warn', 'Pensez à rotater le mot de passe immédiatement si vous êtes en production.');

            return;
        }

        $this->say('warn', "Compte supradmin DÉJÀ PRÉSENT : {$email} — rien n'a été créé.");
        $this->say('warn', 'Son mot de passe est INCHANGÉ : ce seeder ne réinitialise pas un compte existant.');
    }

    /**
     * Écrit sur la console quand il y en a une.
     *
     * `$this->command` est `null` dès que le seeder est appelé hors d'une
     * commande Artisan — c'est le cas d'un `(new SupradminSeeder)->run()` dans
     * un test. Sans cette garde, un seeder qui a parfaitement fait son travail
     * se termine sur un `Call to a member function info() on null`.
     */
    private function say(string $niveau, string $message): void
    {
        $this->command?->{$niveau}($message);
    }
}
