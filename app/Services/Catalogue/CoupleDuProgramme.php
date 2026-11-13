<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use Illuminate\Database\ConnectionInterface;

/**
 * Le couple (classe, matière) du programme LOCAL, `classe_matiere`, écrit par
 * `LocalClasseMatiereLinker` : la règle unique de « cette matière est au
 * programme de cette classe ». La programmation d'une évaluation (D72) et la
 * création d'une séance locale (lot S-matière) la lisent ici.
 *
 * L'appelant a déjà borné la classe et la matière à l'établissement : le couple
 * est unique (`(classe_id, matiere_id)`).
 */
final class CoupleDuProgramme
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Le formateur désigné du couple (`users.id`), `null` si le couple est
     * programmé sans formateur, `false` s'il n'est pas programmé.
     */
    public function formateur(int $classeId, int $matiereId): int|false|null
    {
        $couple = $this->db->table('classe_matiere')
            ->where('classe_id', $classeId)
            ->where('matiere_id', $matiereId)
            ->first(['enseignant_id']);

        if ($couple === null) {
            return false;
        }

        return is_numeric($couple->enseignant_id) ? (int) $couple->enseignant_id : null;
    }

    public function estProgramme(int $classeId, int $matiereId): bool
    {
        return $this->formateur($classeId, $matiereId) !== false;
    }
}
