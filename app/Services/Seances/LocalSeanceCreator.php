<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;

/**
 * #710 — création locale. Jamais d'identifiant local dans klassci_*.
 */
final class LocalSeanceCreator
{
    /**
     * @param  array{titre: string, date_seance: string, matiere_nom?: string, classe_nom?: string}  $payload
     */
    public function create(User $author, array $payload): Seance
    {
        return Seance::query()->create([
            'titre' => $payload['titre'],
            'date_seance' => $payload['date_seance'],
            'matiere_nom' => $payload['matiere_nom'] ?? null,
            'classe_nom' => $payload['classe_nom'] ?? null,
            'created_by' => $author->id,
            'klassci_seance_id' => null,
            'klassci_enseignant_id' => null,
            'klassci_matiere_id' => null,
            'klassci_classe_id' => null,
            'is_active' => true,
            'institution_id' => $author->institution_id,
        ]);
    }
}
