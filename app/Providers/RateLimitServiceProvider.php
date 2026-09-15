<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Rate limiters nommés de l'API (#214).
 *
 * ## Pourquoi ce provider
 *
 * Les routes proxy KLASSCI relaient des appels HTTP coûteux vers une instance
 * tierce. Sans limite par utilisateur, un client (ou un token volé) peut
 * marteler le proxy → surcharge KLASSCI + facture réseau. Ces limiters
 * structurés bornent le débit par utilisateur authentifié.
 *
 * ## Backend cache-agnostique (pas de dépendance Redis dure)
 *
 * `RateLimiter` s'appuie sur le cache store par défaut (`config('cache.default')`).
 * Il fonctionne donc avec `file`, `database`, `redis` ou `memcached` sans
 * changement de code. Redis reste RECOMMANDÉ en production multi-process pour
 * l'atomicité et la performance, mais n'est pas requis — important pour un
 * hébergement mutualisé qui n'exposerait pas Redis.
 *
 * ## Limiters
 *
 *  - `proxy`        : 100 req/min/utilisateur (lecture organisationnelle).
 *  - `proxy-write`  : 30 req/min/utilisateur (écritures notes/présences/statut).
 *  - `search`       : 30 req/min/utilisateur (#548 — /api/search/* n'avait
 *                      aucun throttle, frappe KLASSCI potentiellement fan-out
 *                      ×5 sous-requêtes par appel).
 *
 * Le `supradmin` (gestionnaire plateforme) est exempté (`Limit::none()`) —
 * cohérent avec son bypass tenant existant.
 *
 * Les en-têtes `X-RateLimit-Limit` / `X-RateLimit-Remaining` (+ `Retry-After`
 * et `X-RateLimit-Reset` au 429) sont ajoutés automatiquement par le
 * middleware `throttle:<name>` de Laravel.
 *
 * @see routes/api.php (groupes proxy → middleware throttle:proxy / proxy-write)
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    private const PROXY_READ_PER_MINUTE = 100;

    private const PROXY_WRITE_PER_MINUTE = 30;

    private const VISIO_HEARTBEAT_PER_MINUTE = 12;

    private const SEARCH_PER_MINUTE = 30;

    /**
     * Seule écriture NON authentifiée du système (#812). Deux bornes, parce
     * qu'une seule ne tient pas :
     *
     * - par IP, pour l'usage normal ;
     * - globale par jour, parce que `bootstrap/app.php` déclare
     *   `trustProxies(at: '*')`. Symfony rend alors l'en-tête
     *   `X-Forwarded-For` FOURNI PAR L'APPELANT, et une boucle qui le fait
     *   tourner tombe à chaque fois dans un compteur neuf. Aucun en-tête ne
     *   déplace la seconde borne.
     *
     * Le plafond journalier est généreux pour l'usage réel — une poignée de
     * demandes — et ruineux pour un déversement.
     */
    private const SCHOOL_REQUESTS_PER_MINUTE = 5;

    private const SCHOOL_REQUESTS_PER_DAY = 200;

    public function boot(): void
    {
        RateLimiter::for('proxy', function (Request $request): Limit {
            return $this->limitForUser($request, self::PROXY_READ_PER_MINUTE);
        });

        RateLimiter::for('proxy-write', function (Request $request): Limit {
            return $this->limitForUser($request, self::PROXY_WRITE_PER_MINUTE);
        });

        RateLimiter::for('visio-heartbeat', function (Request $request): Limit {
            return $this->limitForHeartbeat($request);
        });

        RateLimiter::for('search', function (Request $request): Limit {
            return $this->limitForUser($request, self::SEARCH_PER_MINUTE);
        });

        // Un limiteur NOMMÉ, et non un `throttle:5,1` en ligne : la clé anonyme
        // de `ThrottleRequests` est `domaine|ip`, sans chemin ni nom de route.
        // Le seau était donc partagé avec `/auth/login` — cinq dépôts depuis
        // une école derrière une seule IP refusaient la connexion suivante.
        RateLimiter::for('school-requests', function (Request $request): array {
            return [
                Limit::perMinute(self::SCHOOL_REQUESTS_PER_MINUTE)->by((string) $request->ip()),
                Limit::perDay(self::SCHOOL_REQUESTS_PER_DAY)->by('school-requests-global'),
            ];
        });
    }

    /**
     * Construit la limite : illimitée pour le supradmin, sinon bornée par
     * `user->id` (clé stable et isolée par utilisateur). Fallback sur l'IP si
     * l'utilisateur n'est pas résolu (défensif — ces routes sont authentifiées).
     */
    private function limitForUser(Request $request, int $perMinute): Limit
    {
        $user = $request->user();

        // Strict lowercase `'supradmin'` UNIQUEMENT (gestionnaire plateforme).
        // NE PAS utiliser asRoleEnum() : `tryFromString('superAdmin')` normalise
        // l'admin intra-tenant en Role::Supradmin, ce qui exempterait à tort un
        // admin d'institution. Même distinction que ChecksForumAuthorization.
        if ($user instanceof User && $user->isPlatformSupradmin()) {
            return Limit::none();
        }

        $key = $user instanceof User ? (string) $user->id : (string) $request->ip();

        return Limit::perMinute($perMinute)->by($key);
    }

    private function limitForHeartbeat(Request $request): Limit
    {
        $user = $request->user();
        $actor = $user instanceof User ? (string) $user->id : (string) $request->ip();
        $seanceId = (string) ($request->route('seanceId') ?? 'unknown');

        return Limit::perMinute(self::VISIO_HEARTBEAT_PER_MINUTE)
            ->by($actor.':seance:'.$seanceId);
    }
}
