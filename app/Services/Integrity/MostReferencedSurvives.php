<?php

declare(strict_types=1);

namespace App\Services\Integrity;

use Illuminate\Database\DatabaseManager;

/**
 * Survit la ligne qui porte le PLUS de lignes filles (#541).
 *
 * Règle des `evaluations` : entre deux évaluations liées à la même évaluation
 * KLASSCI, celle qui porte les copies des étudiants doit rester visible. La
 * retirer laisserait les `evaluation_submissions` accrochées à une ligne
 * soft-deletée — donc masquée par le scope global — et les notes déjà saisies
 * disparaîtraient de l'interface sans aucun message.
 *
 * Les lignes filles ne sont JAMAIS déplacées : les recoller sur la survivante
 * fusionnerait deux évaluations distinctes (barèmes, questions et tentatives
 * différents). On se contente de choisir la bonne survivante.
 */
final class MostReferencedSurvives extends RankedSurvivorPolicy
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly string $dependentTable,
        private readonly string $foreignKey,
    ) {
    }

    protected function score(string $table, array $row): int
    {
        return $this->db->table($this->dependentTable)
            ->where($this->foreignKey, RowIdentifier::of($row))
            ->count();
    }
}
