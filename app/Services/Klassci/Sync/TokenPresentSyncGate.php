<?php

declare(strict_types=1);

namespace App\Services\Klassci\Sync;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Re-sync seulement si le compte a un jeton KLASSCI et des données périmées,
 * et s'il n'est pas en recul après un échec.
 *
 * Le recul est par compte : l'identifiant d'un compte est global, la clé ne
 * mêle donc jamais deux écoles. Sa durée (1 h par défaut) borne un compte en
 * échec à 24 appels par jour au lieu d'un par requête. La re-synchronisation
 * passive ne touche que des données d'affichage et d'audit (jamais `role`,
 * `email` ni `klassci_enseignant_id`) : une heure de plus sur une donnée
 * tolérée à 24 h ne change aucun droit. Un succès date le compte : l'entrée
 * de recul expire seule.
 */
final class TokenPresentSyncGate implements KlassciSyncGate
{
    private const RECUL_PAR_DEFAUT = 3600;

    public function __construct(private readonly CacheRepository $cache) {}

    public function shouldRefresh(User $user): bool
    {
        if ($user->isKlassciDataFresh()) {
            return false;
        }

        $token = $user->klassci_token;

        return is_string($token) && $token !== '' && ! $this->cache->has($this->cleDeRecul($user));
    }

    public function refreshFailed(User $user): void
    {
        $this->cache->put($this->cleDeRecul($user), true, $this->dureeDuRecul());
    }

    private function cleDeRecul(User $user): string
    {
        return 'klassci:resync:recul:'.$user->id;
    }

    private function dureeDuRecul(): int
    {
        $valeur = config('services.klassci.resync_backoff_seconds', self::RECUL_PAR_DEFAUT);

        return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : self::RECUL_PAR_DEFAUT;
    }
}
