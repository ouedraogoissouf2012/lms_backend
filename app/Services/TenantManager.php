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
     * Obtenir le slug de l'institution courante
     */
    public function slug(): ?string
    {
        return $this->current?->slug;
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
