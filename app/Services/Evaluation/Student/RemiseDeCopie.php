<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Enums\EvaluationSubmissionStatus;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;
use Illuminate\Database\Eloquent\Builder;

/**
 * La remise d'une copie par l'élève, dans les deux types d'école (C28).
 *
 * Rendre une copie, c'est clore une tentative OUVERTE, jamais en fabriquer une.
 * Décision de l'utilisateur : après l'échéance, la copie « est refusée mais gardée
 * en brouillon et verrouillée pour toute modification ».
 *
 * - Dans le délai (échéance + marge) : la copie est notée et passe `soumis`.
 * - Après : elle passe `verrouille`, garde les réponses REÇUES avec la remise
 *   refusée (Q1 ; elles peuvent dater de la marge), et n'est pas notée.
 * - Une copie déjà verrouillée refuse toute nouvelle remise, sans rien écrire.
 *
 * Le verrou est une écriture CONDITIONNELLE (`where status = en_cours`) : entre deux
 * remises simultanées, ou contre une remise dans le délai qui vient de gagner, une
 * seule transition a lieu, et une copie remise n'est jamais écrasée. Cette écriture
 * passe par la requête, pas par le modèle : le journal d'audit (`Auditable`), qui
 * écoute les événements du modèle, ne la voit pas (dette déclarée).
 */
final class RemiseDeCopie
{
    public const REMISE = 'remise';

    public const AUCUNE_TENTATIVE = 'aucune_tentative';

    public const VERROUILLEE = 'verrouillee';

    public function __construct(private readonly EvaluationGradingService $notation) {}

    /**
     * @param  array<int|string, mixed>  $reponses
     * @return array{status: self::REMISE, submission: EvaluationSubmission}|array{status: self::AUCUNE_TENTATIVE|self::VERROUILLEE}
     */
    public function remettre(int $evaluationId, User $eleve, array $reponses): array
    {
        $copie = $this->sesCopies($evaluationId, $eleve)
            ->where('status', EvaluationSubmissionStatus::EnCours->value)
            ->first();

        if ($copie === null) {
            return ['status' => $this->derniereEstVerrouillee($evaluationId, $eleve) ? self::VERROUILLEE : self::AUCUNE_TENTATIVE];
        }

        if ($copie->estEchue()) {
            $this->verrouiller($copie, $reponses);

            return ['status' => self::VERROUILLEE];
        }

        $copie->answers = $reponses;
        $this->notation->submit($copie);

        return ['status' => self::REMISE, 'submission' => $copie];
    }

    /** @param  array<int|string, mixed>  $reponses */
    private function verrouiller(EvaluationSubmission $copie, array $reponses): void
    {
        // Même encodage que le cast `array` du modèle (`Model::asJson`).
        EvaluationSubmission::query()
            ->whereKey($copie->getKey())
            ->where('status', EvaluationSubmissionStatus::EnCours->value)
            ->update([
                'status' => EvaluationSubmissionStatus::Verrouille->value,
                'answers' => json_encode($reponses),
            ]);
    }

    private function derniereEstVerrouillee(int $evaluationId, User $eleve): bool
    {
        $derniere = $this->sesCopies($evaluationId, $eleve)->first();

        return $derniere !== null && $derniere->getRawOriginal('status') === EvaluationSubmissionStatus::Verrouille->value;
    }

    /**
     * Les copies de l'élève sur l'évaluation, la plus récente tentative d'abord.
     *
     * @return Builder<EvaluationSubmission>
     */
    private function sesCopies(int $evaluationId, User $eleve): Builder
    {
        return EvaluationSubmission::query()
            ->where('evaluation_id', $evaluationId)
            ->ownedBy($eleve)
            ->orderByDesc('attempt');
    }
}
