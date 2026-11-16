<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Le limiteur nommé `lecture` (#947, ADR-947-01) : toute lecture authentifiée
 * a une limite de débit.
 *
 * Posé UNE fois sur le groupe `api` (`bootstrap/app.php`), et non route par
 * route : une route GET nouvelle naît limitée. La priorité de Laravel place
 * `ThrottleRequests` juste après l'authentification, le compte est donc connu.
 *
 * ## Ce qui est compté
 *
 * Les lectures (`isMethodSafe`) d'un compte authentifié. Une écriture garde
 * son `throttle:N,1` propre, une route publique son limiteur propre : ici,
 * elles rendent `Limit::none()`, sans double compte. Le supradmin de
 * plateforme est exempté, comme des autres limiteurs (`RateLimitServiceProvider`).
 *
 * ## Une famille, un seau
 *
 * La clé est `lecture|famille|user_id` : un sondage périodique ne consomme
 * pas le budget de navigation. Les seuils sont le double du débit maximal
 * mesuré sur le parcours E2E (borne supérieure, sur une minute), avec un
 * plancher de 300 en navigation : voir ADR-947-01.
 *
 * Une route qui porte déjà son propre `throttle` (plus strict) compte aussi
 * ici, en navigation : c'est le plafond général de lecture du compte. Laravel
 * garde visibles les en-têtes de la limite la plus proche de l'épuisement.
 */
final class LimiteDesLectures
{
    public const NAVIGATION_PAR_MINUTE = 300;

    public const SUIVI_PAR_MINUTE = 60;

    public const AGREGATS_PAR_MINUTE = 30;

    /** Les sondages périodiques du front (période mesurée dans ADR-947-01). */
    private const SUIVI = [
        '#^lms/imports/[^/]+$#',
        '#^lms/seances/[^/]+/recording$#',
        '#^lms/seances/[^/]+/visio-participants$#',
        '#^evaluations/[^/]+/time-status$#',
        '#^quiz-attempts/[^/]+/time-remaining$#',
        '#^notifications/unread-count$#',
        '#^chapters/uploads/[^/]+/status$#',
    ];

    /** Les agrégations de tableau de bord, coûteuses pour toute l'école. */
    private const AGREGATS = [
        '#^admin/analytics/#',
        '#^admin/statistics$#',
        '#^admin/notifications/stats$#',
        '#^admin/audit-log$#',
        '#^files/stats$#',
        '#^teacher/stats$#',
        '#^dashboard/stats$#',
    ];

    public function __invoke(Request $request): Limit
    {
        $compte = $request->user();

        if (! $request->isMethodSafe() || ! $compte instanceof User || $compte->isPlatformSupradmin()) {
            return Limit::none();
        }

        $famille = self::famille($request->path());

        return Limit::perMinute(self::seuil($famille))->by("lecture|{$famille}|{$compte->id}");
    }

    /**
     * La famille d'un chemin d'API, avec ou sans le préfixe `api/` et la version.
     */
    public static function famille(string $chemin): string
    {
        $chemin = (string) preg_replace('#^api/(v\d+/)?#', '', ltrim($chemin, '/'));

        foreach (self::SUIVI as $motif) {
            if (preg_match($motif, $chemin) === 1) {
                return 'suivi';
            }
        }
        foreach (self::AGREGATS as $motif) {
            if (preg_match($motif, $chemin) === 1) {
                return 'agregats';
            }
        }

        return 'navigation';
    }

    private static function seuil(string $famille): int
    {
        return match ($famille) {
            'suivi' => self::SUIVI_PAR_MINUTE,
            'agregats' => self::AGREGATS_PAR_MINUTE,
            default => self::NAVIGATION_PAR_MINUTE,
        };
    }
}
