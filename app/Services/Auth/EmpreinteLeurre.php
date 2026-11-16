<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;
use Throwable;

/**
 * L'empreinte LEURRE du login (#687) : une vérification contre elle donne à un
 * échec le coût d'une vraie vérification, que le compte existe ou non.
 *
 * Sans elle, un identifiant inconnu ne coûtait aucun calcul, et un compte connu
 * avec un mauvais mot de passe un bcrypt : ~23 ms contre ~519 ms, mesuré le
 * 2026-09-26. Le chronomètre suffisait à énumérer les comptes locaux, à
 * commencer par le supradmin.
 *
 * ## Gardée dans le cache, jamais recalculée à chaque requête
 *
 * Sous Apache/PHP, l'état d'un singleton meurt avec la requête. Recalculer le
 * leurre à chaque échec coûterait `make` + `check`, soit DEUX bcrypt contre un
 * pour un mot de passe faux : l'oracle s'inverserait au lieu de se fermer. Elle
 * est donc gardée dans le cache applicatif, dont une lecture coûte de l'ordre de
 * la milliseconde.
 *
 * ## Au coût configuré
 *
 * Un leurre moins coûteux qu'une vraie empreinte serait de nouveau
 * reconnaissable : `needsRehash()` la fait refaire quand le coût configuré
 * change.
 *
 * ## Ce qu'elle protège, et ce qu'elle ne protège pas
 *
 * Elle n'ouvre aucun compte : c'est l'empreinte d'un secret aléatoire aussitôt
 * jeté. Une panne du cache ne casse jamais le login : l'empreinte est alors
 * calculée à la volée, et la panne journalisée.
 *
 * @see docs/adr/2026-11-16-687-01-empreinte-leurre-du-login.md
 */
final class EmpreinteLeurre
{
    public const CLE = 'auth:empreinte-leurre';

    public function __construct(
        private readonly Hasher $hasher,
        private readonly CacheRepository $cache,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function empreinte(): string
    {
        $gardee = null;
        if ($gardee !== null) {
            return $gardee;
        }

        // Le secret n'est jamais conservé : seule son empreinte sert.
        $neuve = $this->hasher->make(Str::random(64));
        $this->garder($neuve);

        return $neuve;
    }

    /** L'empreinte du cache, si elle est au coût configuré. */
    private function gardee(): ?string
    {
        try {
            $valeur = $this->cache->get(self::CLE);
        } catch (Throwable $e) {
            $this->journal->warning('Empreinte leurre : cache illisible, empreinte calculée à la volée', $e);

            return null;
        }

        return is_string($valeur) && $valeur !== '' && ! $this->hasher->needsRehash($valeur) ? $valeur : null;
    }

    private function garder(string $empreinte): void
    {
        try {
            $this->cache->forever(self::CLE, $empreinte);
        } catch (Throwable $e) {
            $this->journal->warning('Empreinte leurre : cache non écrit, elle sera recalculée', $e);
        }
    }
}
