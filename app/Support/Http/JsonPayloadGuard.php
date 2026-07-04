<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Exceptions\UnserializablePayloadException;
use Closure;

/**
 * Garde de sérialisabilité des payloads d'enveloppe JSON (#360).
 *
 * ## Pourquoi
 *
 * Mesuré (PHP 8.3 / Laravel 12) : `json_encode` n'échoue PAS sur une Closure —
 * il l'encode en `{}` (objet sans propriété publique). Un controller qui passe
 * un callable par erreur (oubli de `->toArray()`, mauvaise variable) émettait
 * donc un 200 avec un payload vide, sans aucun signal d'échec. Les resources
 * (`fopen`) jettent déjà nativement (`Type is not supported`) — les Closures
 * sont le seul type courant à corruption SILENCIEUSE, d'où cette garde ciblée.
 *
 * ## Périmètre du parcours
 *
 * Descente récursive dans les TABLEAUX uniquement. On ne pénètre pas les
 * objets : leur sérialisation passe par leur propre `jsonSerialize()`/
 * `toArray()` (Eloquent, Resources) et les inspecter par réflexion coûterait
 * le prix d'une double sérialisation pour un cas d'erreur qui, dans les
 * objets, produit une exception native et non une corruption silencieuse.
 *
 * La profondeur est bornée à celle de `json_encode` (512) : au-delà, on laisse
 * `json_encode` jeter son `Maximum stack depth exceeded` canonique — la garde
 * ne doit jamais s'effondrer avant lui.
 *
 * Parcours ITÉRATIF (pile explicite), pas récursif : une récursion PHP de 512
 * niveaux dépasse `xdebug.max_nesting_level` en dev local (Error fatal au lieu
 * de l'exception canonique) et un tableau circulaire (`$a['self'] = &$a`) la
 * ferait boucler. Avec la pile bornée par MAX_DEPTH, les deux cas terminent et
 * `json_encode` conserve ses erreurs natives (`Recursion detected`, depth).
 *
 * ## Pourquoi une classe statique pure (et pas un service injecté)
 *
 * Fonction pure sans état ni variation de comportement : rien à substituer
 * (au sens LSP, il n'y a pas d'abstraction à créer — comme `array_map`).
 * L'injecter imposerait un constructeur à tous les controllers du trait.
 */
final class JsonPayloadGuard
{
    /** Aligné sur la limite de profondeur par défaut de `json_encode`. */
    private const MAX_DEPTH = 512;

    /**
     * Rejette toute Closure trouvée dans `$value` (descente dans les tableaux).
     *
     * @param  string  $path  Chemin racine pour le message d'erreur (`data`, `meta`, `errors`).
     *
     * @throws UnserializablePayloadException
     */
    public static function rejectClosures(mixed $value, string $path): void
    {
        /** @var list<array{mixed, string, int}> $stack */
        $stack = [[$value, $path, 0]];

        while ($stack !== []) {
            [$current, $currentPath, $depth] = array_pop($stack);

            if ($current instanceof Closure) {
                throw UnserializablePayloadException::closureAt($currentPath);
            }

            if (! is_array($current) || $depth >= self::MAX_DEPTH) {
                continue;
            }

            foreach ($current as $key => $item) {
                $stack[] = [$item, "{$currentPath}.{$key}", $depth + 1];
            }
        }
    }
}
