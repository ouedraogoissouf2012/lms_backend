<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\User;

/**
 * Qui a le droit de voir le corrigé d'une évaluation.
 *
 * ## Pourquoi cette règle existe à un seul endroit
 *
 * `GET /evaluations/{id}` sert **deux publics par le même chemin** :
 * l'enseignant qui rédige ses questions (`useCreateQuestions.js:76`) et
 * l'élève qui compose (`useTakeEvaluation.js:57`). Sans distinction, le
 * navigateur de l'élève détenait les bonnes réponses pendant l'épreuve.
 *
 * Le corrigé est masqué par défaut sur le modèle
 * ({@see \App\Models\EvaluationQuestion}). Ce prédicat décide des rares
 * chemins qui le révèlent — et il est nommé, injecté et testable seul, plutôt
 * que recopié en condition dans chaque appelant : « N chemins qui décident
 * chacun, ce sont N politiques qui divergent » (ADR-760-01).
 *
 * ## Pourquoi ce périmètre
 *
 * Le corrigé se voit par ceux qui peuvent l'écrire. C'est exactement le
 * périmètre que `StoreEvaluationRequest::authorize()` applique déjà à la
 * création : administrateurs et enseignants, jamais les coordinateurs, jamais
 * les élèves.
 *
 * L'élève a bien droit au corrigé — mais sur **sa** copie, et une fois le
 * délai de correction écoulé. Ce chemin-là est distinct, et le révèle
 * explicitement ({@see \App\Http\Controllers\API\Evaluation\Student\EvaluationStudentSubmissionController}).
 */
final class AnswerKeyAudience
{
    public function maySee(User $user): bool
    {
        return $user->isTeacher() || $user->isAdmin();
    }
}
