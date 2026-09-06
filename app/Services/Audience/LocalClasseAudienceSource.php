<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\Classe;
use App\Models\Seance;
use App\Models\User;

/**
 * Vérité locale : pivot `classe_etudiant` (#712).
 * Zéro appel HTTP. Fail-secure si la séance n'a pas de classe.
 */
final class LocalClasseAudienceSource implements ClasseAudienceSource
{
    public function containsStudent(Seance $seance, User $student): bool
    {
        if ($seance->institution_id !== $student->institution_id) {
            return false;
        }

        if (! is_numeric($seance->klassci_classe_id)) {
            return false;
        }

        return Classe::query()
            ->where('institution_id', $student->institution_id)
            ->where('klassci_id', (int) $seance->klassci_classe_id)
            ->whereHas(
                'etudiantsActifs',
                static fn ($query) => $query->where('users.id', $student->id),
            )
            ->exists();
    }
}
