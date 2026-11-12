<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

/**
 * La structure d'une école créée depuis le LMS : aucune filière ni aucun niveau
 * d'études n'y existe, aucun modèle local ne les porte. Des listes VIDES, qui
 * disent ce qui est, plutôt qu'un 503 qui accuserait une panne (lot 3c).
 *
 * La clé des niveaux est `niveaux_etudes`, celle de la documentation KLASSCI
 * (`docs/INTEGRATION_KLASSCI.md`) : la même forme dans les deux types d'école.
 */
final class LocalInstitutionStructureReader implements InstitutionStructureReader
{
    /**
     * @return array{success: true, data: array{filieres: list<never>, niveaux_etudes: list<never>}}
     */
    public function structure(): array
    {
        return [
            'success' => true,
            'data' => [
                'filieres' => [],
                'niveaux_etudes' => [],
            ],
        ];
    }
}
