<?php

declare(strict_types=1);

namespace App\Services\Cache\Purge;

use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Purge ciblée par motif de clé — store `database`, défaut production (#547 R2.1).
 *
 * La colonne `cache.key` stocke `{prefix}{clé applicative}` (cf.
 * `Illuminate\Cache\DatabaseStore::putMany()`). `TenantScopedCache` préfixe ses
 * clés du namespace tenant (`institution_{id}:...`) sur les stores sans tags, ce
 * qui rend chaque tenant identifiable par motif. On supprime donc EXACTEMENT les
 * lignes du tenant courant :
 *
 *     DELETE FROM {table} WHERE key LIKE '{prefix}{namespace}:%' ESCAPE '\'
 *
 * Ce n'est PAS un `Cache::flush()` cross-tenant (interdit CONTRIBUTING.md §E) :
 * le motif est borné au namespace, dérivé du `TenantManager` serveur — jamais
 * d'un input client. Les métacaractères LIKE du namespace sont échappés
 * (défense en profondeur, même si le namespace est serveur-only).
 */
final class DatabaseCachePurger implements TenantCachePurgerInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table,
        private readonly string $prefix,
        private readonly LoggerInterface $logger,
    ) {}

    public function purge(string $namespace): void
    {
        $pattern = $this->escapeLike($this->prefix.$namespace.':').'%';

        $builder = $this->connection->table($this->table);
        // Wrap portable de l'identifiant `key` (mot réservé) via la grammaire du
        // driver, plutôt qu'un backtick codé en dur MySQL-only.
        $column = $builder->getGrammar()->wrap('key');

        // ESCAPE explicite : sans lui, MySQL/SQLite n'interprètent pas notre `\`
        // d'échappement et un namespace contenant `%`/`_` élargirait le motif
        // (fuite cross-tenant théorique). Motif passé en binding paramétré.
        $deleted = $builder
            ->whereRaw($column." LIKE ? ESCAPE '\\'", [$pattern])
            ->delete();

        $this->logger->info('tenant_cache.database_purge', [
            'namespace' => $namespace,
            'deleted' => $deleted,
        ]);
    }

    /**
     * Neutralise `\`, `%` et `_` pour que le namespace soit traité comme un
     * littéral dans le LIKE (l'ordre importe : `\` d'abord).
     */
    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }
}
