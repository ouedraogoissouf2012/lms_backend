<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Models\User;
use App\Services\Klassci\Data\KlassciDataWhitelist;
use App\Services\KlassciProxyService;

/**
 * Le profil KLASSCI LIVE du compte, pour `GET /auth/me`. Extrait tel quel d'`AuthController`
 * (#1536), qui approchait sa limite de 200 lignes.
 *
 * - #568 : isolé PAR utilisateur, par son propre jeton (clé de cache dérivée du hash du jeton).
 *   L'ancien `get('auth/me')` cachait sous une clé commune au tenant : le profil du premier
 *   appelant fuitait à tout l'établissement.
 * - #477 (vecteur B) : filtré par liste blanche AVANT toute exposition. Un KLASSCI compromis ne
 *   peut pas injecter de clés dans la réponse.
 */
final class ProfilKlassciEnDirect
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly KlassciDataWhitelist $listeBlanche,
    ) {}

    /**
     * @return array<string, mixed> Le profil filtré, ou [] sans jeton personnel ou si KLASSCI ne répond pas.
     */
    public function de(User $user): array
    {
        return $this->listeBlanche->filter($this->brut($user));
    }

    /** @return array<string, mixed> L'utilisateur lu par {@see ProfilAuthMe}, ou []. */
    private function brut(User $user): array
    {
        $klassciToken = $user->klassci_token;
        if (! is_string($klassciToken) || $klassciToken === '') {
            return []; // Pas de jeton personnel (auth locale / institution) : pas de profil KLASSCI.
        }

        try {
            $klassciMe = $this->klassciService->requestWithUserToken($klassciToken, 'auth/me', 'GET');

            return ProfilAuthMe::utilisateur($klassciMe, $user) ?? [];
        } catch (\Exception) {
            return [];
        }
    }
}
