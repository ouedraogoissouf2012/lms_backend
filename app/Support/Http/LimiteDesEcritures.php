<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

/**
 * Le limiteur nommé `ecriture` (#1196) : toute écriture authentifiée a une limite
 * de débit, PAR FAMILLE et par compte — le pendant de `lecture` (#947, ADR-947-01).
 *
 * Posé UNE fois sur le groupe `api` (`bootstrap/app.php`) : une route d'écriture
 * nouvelle naît limitée, dans la famille `contenu` par défaut (fermé).
 *
 * ## Pourquoi
 *
 * Un `throttle:N,M` anonyme a pour clé l'id du COMPTE seul
 * (`ThrottleRequests::resolveRequestSignature()`), sans la route ni N : 54 routes
 * authentifiées partageaient un compteur. Un enseignant qui enregistrait sa leçon,
 * ses chapitres et son quiz épuisait le budget de sa séance (E2E 44076995960) ; un
 * élève qui avançait dans une leçon, celui de sa soumission.
 *
 * ## Une famille, un seau
 *
 * La clé est `ecriture|famille|user_id`. Les seuils sont PROVISOIRES : le maximum
 * actuel des routes de chaque famille, sans régression puisqu'aucun seau n'est plus
 * partagé. La mesure par méthode sur le parcours E2E (2 × le maximum, comme
 * ADR-947-01) est l'issue #1199.
 *
 * Une écriture qui porte un limiteur nommé plus strict (imports, consentement
 * visio, proxy, inscriptions…) compte AUSSI ici : c'est le plafond général de sa
 * famille. Les lectures, les invités et le supradmin de plateforme ne sont pas
 * comptés.
 */
final class LimiteDesEcritures
{
    public const PARTICIPATION_PAR_MINUTE = 300;

    public const CONTENU_PAR_MINUTE = 100;

    public const FORUM_PAR_MINUTE = 100;

    public const GESTION_PAR_MINUTE = 60;

    /** Le parcours du compte lui-même : progression, tentatives, visio, notifications. */
    private const PARTICIPATION = [
        '#^lessons/[^/]+/(progress|complete|rating)$#',
        '#^chapters/[^/]+/(complete|time)$#',
        '#^(evaluations|knowledge-checks)/[^/]+/(start|submit)$#',
        '#^quizzes/[^/]+/start$#',
        '#^quiz-attempts/[^/]+/(submit|save-progress)$#',
        '#^lms/seances/[^/]+/(join|leave|heartbeat|hide|unhide)$#',
        '#^lms/visio/consent$#',
        '#^me/inscriptions$#',
        '#^notifications(/|$)#',
        '#^search/history$#',
        '#^auth/(logout|refresh)$#',
    ];

    private const FORUM = ['#^forum/#'];

    /** L'administration de l'école et de la plateforme. */
    private const GESTION = [
        '#^(admin|users|configuration|integrations|training-sessions|classes)(/|$)#',
        '#^(matieres|programs)$#',
        '#^lms/imports(/|$)#',
    ];

    public function __invoke(Request $request): Limit
    {
        $compte = $request->user();

        if ($request->isMethodSafe() || ! $compte instanceof User || $compte->isPlatformSupradmin()) {
            return Limit::none();
        }

        $famille = self::famille($request->path());

        return Limit::perMinute(self::seuil($famille))->by("ecriture|{$compte->id}");
    }

    /**
     * La famille d'un chemin d'API, avec ou sans le préfixe `api/` et la version.
     */
    public static function famille(string $chemin): string
    {
        $chemin = (string) preg_replace('#^api/(v\d+/)?#', '', ltrim($chemin, '/'));

        foreach (['participation' => self::PARTICIPATION, 'forum' => self::FORUM, 'gestion' => self::GESTION] as $famille => $motifs) {
            foreach ($motifs as $motif) {
                if (preg_match($motif, $chemin) === 1) {
                    return $famille;
                }
            }
        }

        return 'contenu';
    }

    private static function seuil(string $famille): int
    {
        return match ($famille) {
            'participation' => self::PARTICIPATION_PAR_MINUTE,
            'forum' => self::FORUM_PAR_MINUTE,
            'gestion' => self::GESTION_PAR_MINUTE,
            default => self::CONTENU_PAR_MINUTE,
        };
    }
}
