<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Models\User;

/**
 * Les résultats d'une évaluation, par élève de sa classe
 * (`GET /evaluations/{id}/results-by-class`), selon le type de l'école (D72, E3).
 *
 * Les deux sources rendent la même forme : `evaluation`, `resultats` et
 * `statistiques` ({@see ResultatsDeClasse}). Elles ne diffèrent que par le
 * roster et par la présentation de l'évaluation : lus chez KLASSCI, ou dans les
 * tables locales.
 *
 * Liée par la fabrique (`EvaluationServiceProvider`), reçue par la MÉTHODE du
 * contrôleur (ADR-EI-01, règle 3).
 */
interface EvaluationResultsReader
{
    /**
     * @return array{status:int, payload:array<string, mixed>}
     */
    public function getResultsByClass(int $evaluationId, User $teacher): array;
}
