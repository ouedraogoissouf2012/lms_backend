<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;

/**
 * Ouvre une tentative, comme `/start` le fait.
 *
 * ## Pourquoi ce helper existe
 *
 * Rendre une copie suppose désormais qu'une tentative soit OUVERTE : le
 * contrôleur ne fabrique plus la copie au moment de la remise, car cette
 * création sautait la fenêtre de passage, le quota et la publication.
 *
 * Les suites qui éprouvent la validation ou la notation n'ont pas à simuler
 * KLASSCI pour autant. Elles posent la précondition avec ce helper, qui écrit
 * exactement les colonnes que `/start` écrit — les DEUX espaces, chacun pour sa
 * raison : `klassci_etudiant_id` porte l'index unique et la propriété,
 * `student_id` est le miroir local.
 *
 * ## Le risque de dérive, et ce qui le tient
 *
 * Un fixture qui recopie la forme d'une écriture peut diverger de cette
 * écriture. Ce n'est pas théorique : c'est la famille même du défaut corrigé
 * ici. Le garde-fou est
 * `Tests\Feature\Evaluation\Student\EvaluationSubmissionOwnerTest`, qui
 * enchaîne le VRAI `/start` puis le VRAI `/submit` — si la forme écrite par
 * `/start` change, il rougit, et ce helper doit suivre.
 */
trait OpensEvaluationAttempt
{
    private function ouvrirTentative(Evaluation $evaluation, User $eleve, int $numero = 1): EvaluationSubmission
    {
        return EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'student_id' => $eleve->id,
            'klassci_etudiant_id' => $eleve->klassci_id,
            'attempt' => $numero,
            'status' => 'en_cours',
            'started_at' => now(),
            'institution_id' => $evaluation->institution_id,
        ]);
    }
}
