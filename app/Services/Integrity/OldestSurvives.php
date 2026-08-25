<?php

declare(strict_types=1);

namespace App\Services\Integrity;

use InvalidArgumentException;

/**
 * Survit la ligne la plus ancienne du groupe (#541).
 *
 * Politique NEUTRE, à ne retenir que pour une table sans notion d'état ni de
 * lignes filles — là où aucune des candidates ne vaut mieux qu'une autre. Dès
 * qu'une donnée dépend du choix (copies d'étudiants, statut d'inscription), une
 * politique explicite doit être utilisée : garder la plus ancienne serait alors
 * une perte de données déguisée en règle.
 */
final class OldestSurvives implements DuplicateSurvivorPolicy
{
    public function survivorId(string $table, array $rows): int
    {
        $identifiers = RowIdentifier::allOf($rows);

        if ($identifiers === []) {
            throw new InvalidArgumentException("Aucune candidate à conserver pour `{$table}`.");
        }

        return min($identifiers);
    }
}
