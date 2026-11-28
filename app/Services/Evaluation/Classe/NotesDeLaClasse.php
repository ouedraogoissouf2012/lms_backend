<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Enums\EtatDeLaCase;
use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Services\Evaluation\Student\CoefficientsDesMatieres;
use App\Services\Evaluation\Student\CopiesFinales;
use App\Services\Evaluation\Student\ResultatsVisibles;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * #1676 : ce que le bilan d'une classe compte, lu en une fois.
 *
 * - Les évaluations PUBLIÉES de la classe, dans son établissement (bornage explicite : le scope
 *   global est ouvert sans tenant), par son id local ou, pour une classe KLASSCI, par son
 *   identité KLASSCI ; dans la période `[du, au]` sur la date d'évaluation.
 * - Seules celles dont les résultats sont VISIBLES ({@see ResultatsVisibles}) : la règle de
 *   « Mes notes ». Une note non publiée ne compte pas dans le rang ; quand la publication des
 *   notes deviendra un geste explicite (#1699), le rang suivra.
 * - Leurs copies FINALES ({@see CopiesFinales}) ; la matière (id local) et son coefficient dans
 *   la classe ({@see CoefficientsDesMatieres}).
 */
final class NotesDeLaClasse
{
    /**
     * @param  Collection<int, Evaluation>  $evaluations
     * @param  Collection<int, EvaluationSubmission>  $copies
     * @param  Collection<int, EvaluationSubmission>  $sansNoteFinale  #1698 : à corriger ou verrouillées
     * @param  array<int, int>  $matieres  id local de la matière, par id d'évaluation
     * @param  array<int, float>  $coefficients  coefficient de la matière, par id d'évaluation
     */
    private function __construct(
        public readonly Collection $evaluations,
        Collection $copies,
        Collection $sansNoteFinale,
        public readonly array $matieres,
        public readonly array $coefficients,
    ) {
        // Indexées une fois par évaluation : chaque recherche ne parcourt que les copies d'UNE
        // évaluation, triées de la plus récente à la plus ancienne ({@see CopiesFinales}).
        foreach ($copies as $copie) {
            $this->parEvaluation[(int) $copie->evaluation_id][] = $copie;
        }
        foreach ($sansNoteFinale as $copie) {
            $this->sansNoteFinale[(int) $copie->evaluation_id][] = $copie;
        }
    }

    /** @var array<int, list<EvaluationSubmission>> */
    private array $parEvaluation = [];

    /** @var array<int, list<EvaluationSubmission>> */
    private array $sansNoteFinale = [];

    public static function lire(
        Classe $classe,
        ?CarbonInterface $du,
        ?CarbonInterface $au,
        CopiesFinales $copiesFinales,
        CoefficientsDesMatieres $coefficients,
    ): self {
        $etablissement = (int) $classe->institution_id;
        $evaluations = Evaluation::query()->withoutGlobalScope('institution')
            ->where('institution_id', $etablissement)
            ->where('is_published', true)
            ->where(static fn ($q) => $q->where('classe_id', $classe->id)
                ->when(is_numeric($classe->klassci_id), static fn ($k) => $k->orWhere('klassci_classe_id', (int) $classe->klassci_id)))
            ->when($du !== null, static fn ($q) => $q->where('date_evaluation', '>=', $du?->copy()->startOfDay()))
            ->when($au !== null, static fn ($q) => $q->where('date_evaluation', '<=', $au?->copy()->endOfDay()))
            ->orderBy('date_evaluation')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Evaluation $evaluation): bool => ResultatsVisibles::pour($evaluation))
            ->values();

        $ids = array_values($evaluations->map(static fn (Evaluation $evaluation): int => (int) $evaluation->id)->all());

        return new self(
            $evaluations,
            $copiesFinales->desEvaluations($ids, $etablissement),
            $copiesFinales->sansNoteFinale($ids, $etablissement),
            $coefficients->matieresLocales($evaluations),
            $coefficients->pour($evaluations),
        );
    }

    /** La copie finale la plus récente de cet élève pour cette évaluation, ou null. */
    public function copieDe(EleveDeLaClasse $eleve, int $evaluation): ?EvaluationSubmission
    {
        foreach ($this->parEvaluation[$evaluation] ?? [] as $copie) {
            if ($eleve->possede($copie)) {
                return $copie;
            }
        }

        return null;
    }

    /**
     * #1698 : l'état d'une case SANS copie finale. Une remise à corriger l'emporte sur une
     * tentative verrouillée : l'élève a rendu une copie, sa note viendra.
     */
    public function etatSansNote(EleveDeLaClasse $eleve, int $evaluation): EtatDeLaCase
    {
        $etat = EtatDeLaCase::Manquant;
        foreach ($this->sansNoteFinale[$evaluation] ?? [] as $copie) {
            if (! $eleve->possede($copie)) {
                continue;
            }
            if ($copie->status !== 'verrouille') {
                return EtatDeLaCase::ACorriger;
            }
            $etat = EtatDeLaCase::EnRetard;
        }

        return $etat;
    }
}
