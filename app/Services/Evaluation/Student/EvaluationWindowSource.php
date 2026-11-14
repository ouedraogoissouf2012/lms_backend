<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;

/**
 * D'où vient la fenêtre de passage d'une évaluation, selon le type d'école
 * (D72, E2b).
 *
 * - École créée depuis KLASSCI : la programmation KLASSCI, lue avec le jeton
 *   de l'élève ({@see KlassciEvaluationWindowSource}).
 * - École créée depuis le LMS : calculée sur la ligne (Q2,
 *   {@see LocalEvaluationWindowSource}) ; elle ne peut pas être invérifiable.
 *
 * Choisie à chaque appel par {@see EvaluationWindowGate}, jamais au
 * constructeur (ADR-EI-01, règle 3).
 */
interface EvaluationWindowSource
{
    /**
     * Un refus qui précède toute lecture : la source KLASSCI exige le jeton de
     * l'élève (`no_token`). Null : rien n'empêche de lire.
     *
     * @return array{status: string}|null
     */
    public function refusAvantLecture(User $eleve): ?array;

    /**
     * `fetched = false` : la fenêtre n'a pas pu être lue (le démarrage échoue
     * fermé hors entraînement, #499). `window = null` : aucune fenêtre connue.
     *
     * @return array{fetched: bool, window: ?array<string, mixed>}
     */
    public function fenetre(User $eleve, Evaluation $evaluation): array;
}
