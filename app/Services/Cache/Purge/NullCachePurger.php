<?php

declare(strict_types=1);

namespace App\Services\Cache\Purge;

use Psr\Log\LoggerInterface;

/**
 * No-op journalisé — stores sans tags NI purge ciblée native (`file`, `array`)
 * (#547 R2.3).
 *
 * Jamais de flush global de repli : ce serait le `Cache::flush()` cross-tenant
 * interdit (CONTRIBUTING.md §E). Les entrées orphelines restantes expirent par
 * TTL. On journalise pour la traçabilité d'exploitation.
 */
final class NullCachePurger implements TenantCachePurgerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function purge(string $namespace): void
    {
        $this->logger->warning('tenant_cache.flush_skipped_unsupported_store', [
            'tag' => $namespace,
        ]);
    }
}
