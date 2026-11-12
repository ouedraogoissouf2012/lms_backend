<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;

/**
 * École créée depuis le LMS : la fenêtre est calculée sur la ligne
 * ({@see LocalEvaluationWindow}, Q2). Aucun jeton n'est exigé, aucun appel ne
 * part vers KLASSCI, et la fenêtre est toujours lue (jamais
 * `window_check_failed`).
 */
final class LocalEvaluationWindowSource implements EvaluationWindowSource
{
    public function refusAvantLecture(User $eleve): ?array
    {
        return null;
    }

    public function fenetre(User $eleve, Evaluation $evaluation): array
    {
        return ['fetched' => true, 'window' => LocalEvaluationWindow::of($evaluation)];
    }
}
