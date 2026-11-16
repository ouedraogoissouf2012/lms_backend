<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Mémorisation des agrégats de recherche globale (#505).
 *
 * Responsabilité unique : décider **combien de temps** un agrégat mérite d'être
 * gardé, et ne le rendre que si sa forme est encore celle attendue. Extraite de
 * {@see GlobalSearchService}, qui n'a pas à connaître les durées ni le format de
 * sérialisation en plus d'orchestrer cinq sources (§1.1, §1.6-S).
 *
 * ## Deux durées, pas un interrupteur
 *
 * Un agrégat COMPLET vit 5 minutes, comme avant. Un agrégat DÉGRADÉ vit 30 s et
 * **emporte son drapeau** : il n'est donc jamais servi comme s'il était complet
 * — le défaut que corrige #505 — sans pour autant supprimer tout cache pendant
 * une panne, ce qui ferait rejouer les scans SQL locaux à chaque frappe.
 *
 * ## Version de clé
 *
 * Le préfixe porte un numéro : la forme mémorisée a changé avec cette issue, et
 * les entrées encore chaudes de la version précédente ne doivent pas être relues
 * avec la nouvelle lecture. La forme est en outre VALIDÉE à la relecture, pour
 * qu'un bucket ajouté ou renommé par un futur déploiement ne se transforme pas
 * en 500 pendant toute la durée du TTL.
 *
 * `v3` : les leçons trouvées suivent désormais la règle des listes
 * (`LessonReadScope::apply`). Un agrégat calculé par l'ancienne règle ne doit
 * pas être resservi après le déploiement.
 *
 * `v4` (#930) : la clé était `md5($query . $userId . $limit)`, une concaténation
 * SANS séparateur. La requête `abc9` du compte 7 et la requête `abc` du compte 97
 * avaient la même clé : l'agrégat d'un coordinateur (comptes, courriels) était servi
 * à un apprenant, d'une autre école au besoin. La clé hache désormais un tableau
 * SÉRIALISÉ (chaque valeur porte sa longueur : aucune ambiguïté, et aucun échec sur
 * une requête en UTF-8 invalide, contrairement à `json_encode`), en sha256 : la
 * requête est saisie par l'utilisateur, une collision md5 se fabrique. Elle porte
 * aussi l'établissement et le rôle, dont l'agrégat dépend : un compte promu ou
 * déplacé ne relit pas un agrégat calculé sous ses anciens droits. Les entrées v3,
 * qui ont pu se confondre, ne sont plus lues.
 *
 * @phpstan-import-type SearchResults from SearchAggregate
 */
final class SearchResultCache
{
    /** TTL d'un agrégat complet (5 minutes) — comportement historique. */
    private const COMPLETE_TTL_SECONDS = 300;

    /** TTL d'un agrégat dégradé : assez court pour que le rétablissement se voie. */
    private const DEGRADED_TTL_SECONDS = 30;

    private const PREFIX = 'global_search_v4_';

    /** Buckets attendus — contrat de forme de l'entrée mémorisée. */
    private const BUCKETS = ['users', 'lessons', 'evaluations', 'classes', 'matieres'];

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    public function get(string $query, User $user, int $limit): ?SearchAggregate
    {
        $cached = $this->cache->get($this->key($query, $user, $limit));

        if (! is_array($cached) || ! is_array($cached['results'] ?? null) || ! is_array($cached['failed'] ?? null)) {
            return null;
        }

        $results = $cached['results'];

        foreach (self::BUCKETS as $bucket) {
            if (! isset($results[$bucket]) || ! is_array($results[$bucket])) {
                return null;
            }
        }

        /** @var SearchResults $results */
        /** @var list<string> $failed */
        $failed = array_values(array_filter($cached['failed'], is_string(...)));

        return new SearchAggregate($results, $failed);
    }

    public function put(string $query, User $user, int $limit, SearchAggregate $aggregate): void
    {
        $this->cache->put(
            $this->key($query, $user, $limit),
            ['results' => $aggregate->results, 'failed' => $aggregate->failedSources],
            $aggregate->isComplete() ? self::COMPLETE_TTL_SECONDS : self::DEGRADED_TTL_SECONDS,
        );
    }

    private function key(string $query, User $user, int $limit): string
    {
        return self::PREFIX . hash('sha256', serialize([$query, $user->id, $user->institution_id, $user->role, $limit]));
    }
}
