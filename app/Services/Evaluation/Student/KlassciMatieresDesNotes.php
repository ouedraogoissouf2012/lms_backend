<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Support\Logging\CaughtExceptionLog;

/**
 * Les matières de « Mes notes » dans une école KLASSCI : comportement d'avant E4a,
 * déplacé sans changement depuis `StudentGradesAggregator` et
 * `EvaluationStudentListController::myGrades`.
 *
 * - Sans identité KLASSCI, l'élève est refusé (401).
 * - Regroupement par `klassci_matiere_id`, 0 sans matière.
 * - Noms lus chez KLASSCI (`getMatieres`) avec le jeton de l'élève, en fail-soft :
 *   une panne laisse le nom gardé sur l'évaluation.
 */
final class KlassciMatieresDesNotes implements MatieresDesNotes
{
    public function __construct(
        private readonly KlassciProxyService $klassci,
        private readonly CaughtExceptionLog $journal,
    ) {}

    public function refuse(User $eleve): bool
    {
        return ! $eleve->klassci_id;
    }

    public function cleDe(Evaluation $evaluation): int
    {
        return (int) ($evaluation->klassci_matiere_id ?? 0);
    }

    public function noms(User $eleve, array $cles): array
    {
        $jeton = $eleve->klassci_token;
        if (! is_string($jeton) || $jeton === '') {
            return [];
        }

        $noms = [];
        try {
            $reponse = $this->klassci->getMatieres($jeton);
            if (($reponse['success'] ?? false) && is_array($reponse['data'] ?? null)) {
                foreach ($reponse['data'] as $matiere) {
                    if (is_array($matiere) && is_numeric($matiere['id'] ?? null) && is_string($matiere['nom'] ?? null)) {
                        $noms[(int) $matiere['id']] = $matiere['nom'];
                    }
                }
            }
        } catch (\Exception $e) {
            $this->journal->warning('Impossible de récupérer les matières depuis KLASSCI', $e);
        }

        return $noms;
    }
}
