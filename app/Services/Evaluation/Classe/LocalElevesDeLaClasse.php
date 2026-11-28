<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Models\Classe;
use App\Models\User;

/**
 * #1676, école créée depuis le LMS : les élèves INSCRITS et actifs de la classe, comptes de son
 * établissement seulement (la règle de `LocalEvaluationResultsReader::inscritsActifs()`).
 */
final class LocalElevesDeLaClasse implements ElevesDeLaClasse
{
    public function de(Classe $classe, User $lecteur): array
    {
        $inscrits = $classe->etudiantsActifs()
            ->whereIn('users.id', User::query()->where('institution_id', $classe->institution_id)->select('id'))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.klassci_id']);

        return array_values($inscrits->map(static fn (User $eleve): EleveDeLaClasse => new EleveDeLaClasse(
            (int) $eleve->id,
            is_numeric($eleve->klassci_id) ? [(int) $eleve->klassci_id] : [],
            (string) $eleve->name,
        ))->all());
    }
}
