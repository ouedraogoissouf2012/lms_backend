<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\Evaluation\QuestionsDeLaCopie;
use Carbon\CarbonInterface;

/**
 * Ce qu'est une tentative au moment où elle naît : sa propriété, son numéro, son échéance (C28)
 * et, depuis #1702, les questions qui lui sont POSÉES ({@see QuestionsDeLaCopie}). Extrait de
 * `EvaluationAttemptStateService`, qui garde la course entre deux démarrages et la reprise.
 */
final class NouvelleTentative
{
    public function __construct(private readonly QuestionsDeLaCopie $questionsDeLaCopie) {}

    /**
     * @param  ?array<string, mixed>  $window
     * @return array<string, mixed>
     */
    public function attributs(Evaluation $evaluation, User $user, int $tentative, bool $isPracticeMode, ?array $window, CarbonInterface $debut): array
    {
        return [
            'evaluation_id' => $evaluation->id,
            // Les DEUX espaces sont écrits, et chacun pour sa raison :
            // `student_id` porte la propriété (EvaluationSubmission::
            // scopeOwnedBy, unique `eval_sub_student_unique`, D72) ;
            // `klassci_etudiant_id`, nul dans une école créée depuis le LMS,
            // sert l'unique `eval_sub_unique` et le renvoi des notes vers KLASSCI.
            'student_id' => $user->id,
            'klassci_etudiant_id' => $user->klassci_id,
            'attempt' => $tentative,
            'status' => 'en_cours',
            'started_at' => $debut,
            // C28 : l'échéance, figée au démarrage ; la remise la compare sans KLASSCI.
            'ends_at' => EcheanceDeTentative::pour($evaluation, $window, $debut, $isPracticeMode),
            // Scope tenant explicite herite de l'evaluation (fix E2E #211).
            'institution_id' => $evaluation->institution_id,
            'feedback' => $isPracticeMode ? '[PRACTICE] Entraînement - note non officielle' : null,
            // #1702 (D2) : les questions posées et leur ordre, figés pour la tentative.
            'presentation' => $this->questionsDeLaCopie->presenter($evaluation),
        ];
    }
}
