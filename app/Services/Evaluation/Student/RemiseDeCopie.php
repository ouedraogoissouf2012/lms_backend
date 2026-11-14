<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Enums\EvaluationSubmissionStatus;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * La remise d'une copie par l'élève, dans les deux types d'école (C28).
 *
 * Rendre une copie, c'est clore une tentative OUVERTE, jamais en fabriquer une.
 * Décision de l'utilisateur : après l'échéance, la copie « est refusée mais gardée
 * en brouillon et verrouillée pour toute modification ».
 *
 * - Dans le délai (échéance + marge) : la note est calculée en mémoire, puis la copie
 *   passe `soumis` avec ses réponses et sa note en UNE écriture conditionnelle (C39) :
 *   deux remises simultanées ne la notent qu'une fois, et aucune copie ne reste
 *   `soumis` sans note.
 * - Après : elle passe `verrouille`, garde les réponses REÇUES avec la remise
 *   refusée (Q1 ; elles peuvent dater de la marge), et n'est pas notée.
 * - Une copie déjà verrouillée refuse toute nouvelle remise, sans rien écrire.
 *
 * La notation et le verrou sont des écritures CONDITIONNELLES (`where status = en_cours`) : entre deux
 * remises simultanées, ou contre une remise dans le délai qui vient de gagner, une
 * seule transition a lieu, et une copie remise n'est jamais écrasée. La perdante
 * relit la copie et dit son état RÉEL (revue de Fille 1, O1) : déjà remise, un
 * conflit ; déjà verrouillée, le verrou. Ces écritures
 * passent par la requête, pas par le modèle : le journal d'audit (`Auditable`), qui
 * écoute les événements du modèle, ne la voit pas (dette déclarée).
 */
final class RemiseDeCopie
{
    public const REMISE = 'remise';

    public const AUCUNE_TENTATIVE = 'aucune_tentative';

    public const VERROUILLEE = 'verrouillee';

    /** La copie vient d'être remise, dans le délai, par une requête concurrente. */
    public const CONFLIT = 'conflit';

    public function __construct(private readonly EvaluationGradingService $notation) {}

    /**
     * @param  array<int|string, mixed>  $reponses
     * @return array{status: self::REMISE, submission: EvaluationSubmission}|array{status: self::AUCUNE_TENTATIVE|self::VERROUILLEE|self::CONFLIT}
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
            return ['status' => $this->verrouiller($copie, $reponses) === 1 ? self::VERROUILLEE : $this->etatReel($copie)];
        }

        if (! $this->noter($copie, $reponses)) {
            return ['status' => $this->etatReel($copie)];
        }

        return ['status' => self::REMISE, 'submission' => $copie];
    }

    /**
     * Dans le délai (C39) : la note est CALCULÉE en mémoire (calcul pur, sans écriture),
     * puis réponses, statut, `submitted_at` et note s'écrivent en UNE instruction
     * conditionnelle (`where status = en_cours`). Une seule transition ; jamais de copie
     * `soumis` sans note si le calcul échoue. Faux : une requête concurrente a gagné.
     *
     * @param  array<int|string, mixed>  $reponses
     */
    private function noter(EvaluationSubmission $copie, array $reponses): bool
    {
        // UN instant pour la remise : la base et la réponse rendent le même. Passé
        // explicitement, `updated_at` prime sur celui qu'ajoute `Builder::update`.
        $maintenant = Carbon::now();
        $copie->answers = $reponses;
        $copie->status = EvaluationSubmissionStatus::Soumis->value;
        $copie->submitted_at = $maintenant;
        $copie->updated_at = $maintenant;
        $this->notation->calculateScore($copie);

        $brut = $copie->getAttributes();
        $gagnee = EvaluationSubmission::query()
            ->whereKey($copie->getKey())
            ->where('status', EvaluationSubmissionStatus::EnCours->value)
            ->update([
                'answers' => $brut['answers'],
                'status' => EvaluationSubmissionStatus::Soumis->value,
                'submitted_at' => $brut['submitted_at'],
                'updated_at' => $brut['updated_at'],
                'score' => $brut['score'],
                'note_sur_20' => $brut['note_sur_20'],
            ]);

        if ($gagnee !== 1) {
            return false;
        }

        $copie->syncOriginal();

        return true;
    }

    /**
     * @param  array<int|string, mixed>  $reponses
     * @return int le nombre de copies verrouillées : 1, ou 0 si une requête concurrente a gagné
     */
    private function verrouiller(EvaluationSubmission $copie, array $reponses): int
    {
        // Même encodage que le cast `array` du modèle (`Model::asJson`).
        return EvaluationSubmission::query()
            ->whereKey($copie->getKey())
            ->where('status', EvaluationSubmissionStatus::EnCours->value)
            ->update([
                'status' => EvaluationSubmissionStatus::Verrouille->value,
                'answers' => json_encode($reponses),
            ]);
    }

    /**
     * L'état RÉEL d'une copie que la course a fait perdre : remise par la gagnante
     * (conflit), verrouillée par une autre remise tardive, ou disparue (purgée entre la
     * lecture et l'écriture) : il n'y a alors plus de tentative en cours.
     *
     * @return self::CONFLIT|self::VERROUILLEE|self::AUCUNE_TENTATIVE
     */
    private function etatReel(EvaluationSubmission $copie): string
    {
        $statut = EvaluationSubmission::query()->whereKey($copie->getKey())->value('status');

        if ($statut === null) {
            return self::AUCUNE_TENTATIVE;
        }

        return in_array($statut, [EvaluationSubmissionStatus::Soumis->value, EvaluationSubmissionStatus::Corrige->value], true)
            ? self::CONFLIT
            : self::VERROUILLEE;
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
