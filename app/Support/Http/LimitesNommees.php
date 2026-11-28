<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Les limites propres à une route (#1196), chacune sous son NOM : une lecture plus
 * stricte que `lecture`, une route sans compte, une écriture plus stricte que sa
 * famille d'`ecriture`.
 *
 * Avant, chacune portait un `throttle:N,M` anonyme, dont la clé est l'id du compte
 * seul, ou `domaine|IP` sans compte (`ThrottleRequests::resolveRequestSignature()`) :
 * toutes partageaient UN compteur. Derrière le NAT d'une école, les diapositives
 * lues consommaient le budget de connexion de toute l'école.
 *
 * Désormais la clé est `nom|compte`, ou `nom|IP` sans compte. Les seuils sont ceux
 * d'avant, chacun isolé : aucune limite ne se resserre. Le supradmin de plateforme
 * est exempté des limites par compte, comme des autres limiteurs.
 */
final class LimitesNommees
{
    /**
     * nom => [seuil par minute, clé : `ip` ou `compte`].
     *
     * @var array<string, array{0: int, 1: 'ip'|'compte'}>
     */
    public const TABLE = [
        'connexion' => [10, 'ip'],
        'mot-de-passe' => [5, 'ip'],
        'activation' => [5, 'ip'],
        'diapositives' => [120, 'ip'],
        'video' => [240, 'ip'],
        'webhook-visio' => [60, 'ip'],
        'comptes-liste' => [60, 'compte'],
        'configuration-lecture' => [60, 'compte'],
        'rapports-suivi' => [60, 'compte'],
        'rapports-telechargement' => [30, 'compte'],
        'source-chapitre' => [30, 'compte'],
        'visio-active' => [300, 'compte'],
        'imports' => [10, 'compte'],
        'consentement-visio' => [10, 'compte'],
        // #1275 : l'administration définit un mot de passe, rarement ; une rafale est suspecte.
        'mot-de-passe-defini' => [10, 'compte'],
        // #1650 : une annonce part à toute une classe ; une rafale inonderait ses élèves.
        'annonce' => [10, 'compte'],
        // #1705 : un export porte toutes les données d'une personne ; une rafale est suspecte.
        'export-donnees' => [10, 'compte'],
    ];

    public static function enregistrer(): void
    {
        foreach (self::TABLE as $nom => [$seuil, $par]) {
            RateLimiter::for($nom, static fn (Request $request): Limit => self::limite($nom, $seuil, $par, $request));
        }
    }

    /** @param  'ip'|'compte'  $par */
    public static function limite(string $nom, int $seuil, string $par, Request $request): Limit
    {
        $compte = $request->user();

        if ($par === 'compte' && $compte instanceof User) {
            return $compte->isPlatformSupradmin()
                ? Limit::none()
                : Limit::perMinute($seuil)->by("{$nom}|{$compte->id}");
        }

        return Limit::perMinute($seuil)->by("{$nom}|".(string) $request->ip());
    }
}
