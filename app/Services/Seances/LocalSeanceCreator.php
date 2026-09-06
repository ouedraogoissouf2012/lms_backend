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
     * @param  array<string, mixed>  $payload
     */
    public function create(User $author, array $payload): Seance
    {
        $titre = is_string($payload['titre'] ?? null) ? $payload['titre'] : '';
        $dateSeance = is_string($payload['date_seance'] ?? null) ? $payload['date_seance'] : '';

        return Seance::query()->create([
            'titre' => $titre,
            'date_seance' => $dateSeance,
            'matiere_nom' => is_string($payload['matiere_nom'] ?? null) ? $payload['matiere_nom'] : null,
            'classe_nom' => is_string($payload['classe_nom'] ?? null) ? $payload['classe_nom'] : null,
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
