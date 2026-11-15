<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * L'utilisateur d'une réponse `GET auth/me` de KLASSCI, ou `null` s'il est illisible.
 *
 * `auth/me` rend l'utilisateur DANS `data` (`docs/INTEGRATION_KLASSCI.md`,
 * « GET /auth/me », et la forme relevée dans le cache de développement). C'est
 * `auth/login` qui le rend sous `data.user`. Les deux lecteurs d'`auth/me`
 * lisaient `data.user` : la re-synchronisation ne datait jamais le compte, et
 * le compte périmé rappelait KLASSCI à chaque expiration du cache par jeton.
 *
 * Aucune autre forme n'est acceptée : aucune source ne l'atteste, l'accepter
 * serait l'inventer. Une réponse illisible n'est plus muette : un avertissement
 * à message fixe, avec des clés sûres, jamais le corps.
 *
 * Point unique de la règle : `EnsureKlassciSync` (re-synchronisation) et
 * `AuthController` (profil live) la lisent ici.
 */
final class ProfilAuthMe
{
    /**
     * @param  array<mixed>  $reponse
     * @return array<string, mixed>|null
     */
    public static function utilisateur(array $reponse, User $compte): ?array
    {
        $utilisateur = $reponse['data'] ?? null;

        if (is_array($utilisateur) && is_int($utilisateur['id'] ?? null)) {
            /** @var array<string, mixed> $utilisateur */
            return $utilisateur;
        }

        Log::warning('klassci_auth_me_illisible', [
            'user_id' => $compte->id,
            'institution_id' => $compte->institution_id,
        ]);

        return null;
    }
}
