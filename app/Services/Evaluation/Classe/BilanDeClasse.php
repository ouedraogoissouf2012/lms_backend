<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Evaluation\Student\CoefficientsDesMatieres;
use App\Services\Evaluation\Student\CopiesFinales;
use App\Services\Evaluation\Student\Moyennes;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * #1676 : moyennes, rangs et réussite d'une classe. UN seul calculateur, que le carnet de notes
 * (#1698) réutilisera : il rend déjà la grille élèves × évaluations (`evaluations` et
 * `eleves[].notes`).
 *
 * Les règles sont celles de « Mes notes », sans seconde définition :
 * - la note qui compte : {@see CopiesFinales}, la plus récente par élève et par évaluation ;
 * - la moyenne d'une matière : pondérée par le coefficient de chaque évaluation ({@see Moyennes}) ;
 * - la moyenne générale : pondérée par le coefficient de la matière dans la classe
 *   ({@see CoefficientsDesMatieres}) ;
 * - rien à moyenner : `null`, jamais un 0 fabriqué.
 *
 * Les deux volets calculent sur les notes du LMS (`source: "lms"`) : dans une école KLASSCI,
 * KLASSCI peut tenir d'autres notes, que ce bilan ne voit pas.
 */
final class BilanDeClasse
{
    public const SEUIL_PAR_DEFAUT = 10.0;

    public const SOURCE = 'lms';

    public function __construct(
        private readonly ElevesDeLaClasse $eleves,
        private readonly CopiesFinales $copies,
        private readonly CoefficientsDesMatieres $coefficients,
    ) {}

    /** @return array<string, mixed> */
    public function de(Classe $classe, User $lecteur, ?CarbonInterface $du, ?CarbonInterface $au): array
    {
        $eleves = $this->eleves->de($classe, $lecteur);
        $notes = NotesDeLaClasse::lire($classe, $du, $au, $this->copies, $this->coefficients);
        $coefficients = self::coefficientsParMatiere($notes);
        $libelles = $this->libelles($classe, $notes);

        $lignes = [];
        foreach ($eleves as $eleve) {
            $lignes[$eleve->cle()] = LigneDeLEleve::calculer($eleve, $notes, $coefficients);
        }
        $moyennes = array_map(static fn (array $ligne): ?float => $ligne['moyenne_generale'], $lignes);
        foreach (Classement::rangs($moyennes) as $cle => $rang) {
            $lignes[$cle]['rang'] = $rang;
        }
        $seuil = self::seuil($classe);

        return [
            'classe' => ['id' => (int) $classe->id, 'libelle' => (string) $classe->libelle],
            'periode' => ['du' => $du?->toDateString(), 'au' => $au?->toDateString()],
            'source' => self::SOURCE,
            'seuil_reussite' => $seuil,
            'evaluations' => self::colonnes($notes),
            'matieres' => self::matieres($libelles, $coefficients),
            'eleves' => array_values($lignes),
            'statistiques' => Classement::statistiques($moyennes, $seuil),
            'couverture' => $this->couverture($classe, $notes, $lignes, $libelles),
        ];
    }

    /** Le seuil de réussite de l'école (`settings.seuil_reussite`, sur 20), 10 par défaut. */
    public static function seuil(Classe $classe): float
    {
        // Par le modèle : `value()` rendrait le JSON brut de la colonne, sans le cast `array`.
        $reglages = Institution::query()->find($classe->institution_id, ['id', 'settings'])?->settings;
        $seuil = is_array($reglages) ? ($reglages['seuil_reussite'] ?? null) : null;

        return is_numeric($seuil) && (float) $seuil >= 0 && (float) $seuil <= 20 ? (float) $seuil : self::SEUIL_PAR_DEFAUT;
    }

    /** @return array<int, float> coefficient de la matière (id local), dans cette classe */
    private static function coefficientsParMatiere(NotesDeLaClasse $notes): array
    {
        $parMatiere = [];
        foreach ($notes->matieres as $evaluation => $matiere) {
            $parMatiere[$matiere] ??= $notes->coefficients[$evaluation] ?? CoefficientsDesMatieres::NEUTRE;
        }

        return $parMatiere;
    }

    /** @return array<int, string> le libellé de chaque matière du programme ou des notes */
    private function libelles(Classe $classe, NotesDeLaClasse $notes): array
    {
        $ids = array_values(array_unique(array_filter([...self::programme($classe), ...array_values($notes->matieres)])));
        if ($ids === []) {
            return [];
        }

        $libelles = [];
        foreach (Matiere::query()->withoutGlobalScope('institution')->where('institution_id', $classe->institution_id)->whereKey($ids)->get(['id', 'libelle']) as $matiere) {
            $libelles[(int) $matiere->id] = (string) $matiere->libelle;
        }

        return $libelles;
    }

    /** @return list<int> les matières au programme de la classe */
    private static function programme(Classe $classe): array
    {
        return array_values(DB::table('classe_matiere')->where('classe_id', $classe->id)
            ->pluck('matiere_id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all());
    }

    /** @return list<array<string, mixed>> les colonnes de la grille */
    private static function colonnes(NotesDeLaClasse $notes): array
    {
        return array_values($notes->evaluations->map(static fn (Evaluation $evaluation): array => [
            'id' => (int) $evaluation->id,
            'titre' => (string) $evaluation->titre,
            'matiere_id' => ($notes->matieres[$evaluation->id] ?? 0) ?: null,
            'date_evaluation' => $evaluation->date_evaluation?->toIso8601String(),
            'coefficient' => (float) $evaluation->coefficient,
        ])->all());
    }

    /**
     * @param  array<int, string>  $libelles
     * @param  array<int, float>  $coefficients
     * @return list<array{matiere_id: int, libelle: string|null, coefficient: float}>
     */
    private static function matieres(array $libelles, array $coefficients): array
    {
        $matieres = [];
        foreach ($coefficients as $matiere => $coefficient) {
            if ($matiere !== 0) {
                $matieres[] = ['matiere_id' => $matiere, 'libelle' => $libelles[$matiere] ?? null, 'coefficient' => $coefficient];
            }
        }

        return $matieres;
    }

    /**
     * @param  array<string, array{notes: list<array{evaluation_id: int, note: float}>}>  $lignes
     * @param  array<int, string>  $libelles
     * @return array{matieres_sans_evaluation: list<array{matiere_id: int, libelle: string|null}>, evaluations_sans_note: list<int>}
     */
    private function couverture(Classe $classe, NotesDeLaClasse $notes, array $lignes, array $libelles): array
    {
        $evaluees = array_values(array_unique($notes->matieres));
        $sansEvaluation = [];
        foreach (array_values(array_unique(self::programme($classe))) as $matiere) {
            if (! in_array($matiere, $evaluees, true)) {
                $sansEvaluation[] = ['matiere_id' => $matiere, 'libelle' => $libelles[$matiere] ?? null];
            }
        }

        $notees = [];
        foreach ($lignes as $ligne) {
            foreach ($ligne['notes'] as $note) {
                $notees[$note['evaluation_id']] = true;
            }
        }

        return [
            'matieres_sans_evaluation' => $sansEvaluation,
            'evaluations_sans_note' => array_values(array_filter(
                array_values($notes->evaluations->map(static fn (Evaluation $evaluation): int => (int) $evaluation->id)->all()),
                static fn (int $id): bool => ! isset($notees[$id]),
            )),
        ];
    }
}
