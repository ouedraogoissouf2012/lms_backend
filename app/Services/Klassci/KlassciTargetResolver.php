<?php

declare(strict_types=1);

namespace App\Services\Klassci;

/**
 * Abstraction fine (#578) : « quelle est la cible réseau KLASSCI du contexte
 * courant ? ». Consommée par {@see KlassciCircuitBreaker} pour cloisonner son
 * état par serveur au lieu d'un état global multi-tenant.
 *
 * Segrégée (§1.6 I) d'un seul membre — le breaker n'a besoin que de l'URL de
 * base, pas du token ni des garanties de validité. Implémentée par
 * {@see KlassciConfigResolver} (classe `final`, donc non mockable) ; passer par
 * cette interface rend le breaker substituable par un double de test sans
 * contournement (§1.6 L) et évite tout accès statique/Facade (§1.6 D).
 *
 * @see .claude/specs/578-circuit-breaker-per-tenant/design.md §2.1
 */
interface KlassciTargetResolver
{
    /**
     * URL de base KLASSCI résolue pour le contexte courant, ou `null` si aucune
     * cible ne peut être déterminée (le breaker retombe alors sur une partition
     * de repli globale).
     */
    public function baseUrl(): ?string;
}
