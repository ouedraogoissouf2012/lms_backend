<?php

declare(strict_types=1);

namespace App\Services\Integrity;

/**
 * Survit la ligne portant la valeur PRIVILÉGIÉE d'une colonne d'état (#541).
 *
 * Règle de `classe_etudiant` : entre plusieurs inscriptions d'un même étudiant
 * dans une même classe, celle qui vaut `statut = 'actif'` doit rester. Garder
 * une ligne `abandonne` ferait sortir l'étudiant de sa propre classe —
 * `Classe::etudiantsActifs()` filtre sur ce pivot — jusqu'à ce qu'un prochain
 * sync KLASSCI répare le statut, sans que personne ne soit averti entre-temps.
 */
final class PreferredValueSurvives extends RankedSurvivorPolicy
{
    public function __construct(
        private readonly string $column,
        private readonly string $preferred,
    ) {
    }

    protected function score(string $table, array $row): int
    {
        $value = $row[$this->column] ?? null;

        return is_scalar($value) && (string) $value === $this->preferred ? 1 : 0;
    }
}
