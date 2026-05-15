<?php

namespace App\Services;

use App\Models\Institution;

class TenantManager
{
    private ?Institution $current = null;

    /**
     * Définir l'institution courante
     */
    public function set(Institution $institution): void
    {
        $this->current = $institution;
    }

    /**
     * Réinitialiser le tenant courant à null.
     *
     * Utilisé dans :
     * - Les tests, pour simuler explicitement « aucun tenant résolu »
     *   et vérifier le comportement fail-secure (CRITICAL-06).
     * - Les jobs/commands d'administration qui veulent forcer un état propre
     *   avant de fixer un tenant cible.
     *
     * NE PAS appeler en cours de requête HTTP — un changement de tenant en
     * cours de requête est une faille de sécurité (cf. CRITICAL-07).
     */
    public function reset(): void
    {
        $this->current = null;
    }

    /**
     * Obtenir l'institution courante
     */
    public function get(): ?Institution
    {
        return $this->current;
    }

    /**
     * Obtenir l'ID de l'institution courante
     */
    public function id(): ?int
    {
        return $this->current?->id;
    }

    /**
     * Obtenir l'ID de l'institution courante (FAIL-SECURE)
     *
     * Lance une exception si la résolution du tenant a échoué.
     * CRITICAL-06: Prévient data leaks cross-tenant en cas d'init échouée.
     *
     * @throws \RuntimeException si tenant non résolu
     */
    public function getResolved(): int
    {
        if (!$this->current?->id) {
            throw new \RuntimeException(
                'CRITICAL: Tenant resolution failed. Institution not initialized. ' .
                'This is a security failure - cannot proceed without tenant context.'
            );
        }

        return $this->current->id;
    }

    /**
     * Obtenir le slug de l'institution courante
     */
    public function slug(): ?string
    {
        return $this->current?->slug;
    }

    /**
     * Obtenir le slug de l'institution courante (FAIL-SECURE)
     *
     * Lance une exception si la résolution du tenant a échoué.
     * CRITICAL-06: Prévient data leaks cross-tenant en cas d'init échouée.
     *
     * @throws \RuntimeException si tenant non résolu
     */
    public function getResolvedSlug(): string
    {
        if (!$this->current?->slug) {
            throw new \RuntimeException(
                'CRITICAL: Tenant resolution failed. Institution slug not available. ' .
                'This is a security failure - cannot proceed without tenant context.'
            );
        }

        return $this->current->slug;
    }

    /**
     * Retourne la config KLASSCI de l'institution courante
     * Fallback sur config/services.php si pas d'institution
     */
    public function klassciConfig(): array
    {
        if ($this->current) {
            return $this->current->getKlassciConfig();
        }

        return [
            'url' => config('services.klassci.url'),
            'token' => config('services.klassci.token'),
        ];
    }
}
