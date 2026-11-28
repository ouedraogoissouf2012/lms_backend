<?php

declare(strict_types=1);

namespace App\Services\Donnees\Export;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\Student\ResultatsVisibles;

/**
 * Les copies d'évaluation de l'élève (#1705). Une copie lui appartient selon LA règle des copies, `ownedBy` (auteur,
 * ou identité KLASSCI d'une copie sans auteur, dans son école). Une note qu'il ne voit pas encore
 * ({@see ResultatsVisibles}) n'en sort pas : l'export ne publie pas un résultat.
 */
final class SectionCopies implements SectionDExport
{
    private const DECIMALES = ['score', 'note_sur_20'];

    public function cle(): string
    {
        return 'copies';
    }

    public function titre(): string
    {
        return 'Copies d\'évaluation';
    }

    public function lignes(User $eleve, int $ecole): array
    {
        $copies = EvaluationSubmission::query()->withoutGlobalScopes()->ownedBy($eleve)
            ->with(['evaluation' => static fn ($q) => $q->withoutGlobalScopes()->withTrashed()])
            ->orderBy('id')->get();

        return array_values($copies->map(fn (EvaluationSubmission $copie): array => $this->ligne($copie))->all());
    }

    /** @return array<string, mixed> */
    private function ligne(EvaluationSubmission $copie): array
    {
        $evaluation = $copie->evaluation;
        $ligne = [
            'evaluation' => $evaluation?->titre, 'type' => $evaluation?->type, 'tentative' => $copie->attempt,
            'statut' => $copie->status, 'commencee_le' => $copie->started_at?->toIso8601String(),
            'remise_le' => $copie->submitted_at?->toIso8601String(), 'reponses' => $copie->answers,
            'score' => $copie->score, 'note_sur_20' => $copie->note_sur_20, 'manual_points' => $copie->manual_points,
            'feedback' => $copie->feedback, 'corrigee_le' => $copie->graded_at?->toIso8601String(),
        ];
        foreach (self::DECIMALES as $champ) {
            $ligne[$champ] = is_numeric($ligne[$champ]) ? (float) $ligne[$champ] : null;
        }

        return $evaluation instanceof Evaluation && ResultatsVisibles::pour($evaluation)
            ? $ligne
            : array_diff_key($ligne, array_flip(ResultatsVisibles::CHAMPS));
    }
}
