<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\Matiere;
use Illuminate\Support\Facades\DB;

/**
 * #1676 : le coefficient de la MATIÈRE d'une évaluation, dans SA classe. C'est lui qui pondère
 * la moyenne générale, dans « Mes notes » comme dans le bilan d'une classe : une seule règle.
 *
 * Dans l'ordre : le coefficient de la matière dans la classe (`classe_matiere.coefficient`) ;
 * à défaut, celui de la matière (`matieres.coefficient`) ; à défaut, 1. Rien n'est fabriqué :
 * 1 est le neutre, celui qu'avaient toutes les matières avant cette règle.
 *
 * Les deux volets : une évaluation locale porte `matiere_id` et `classe_id` ; une évaluation
 * KLASSCI porte leurs identités KLASSCI, lues sur les miroirs locaux de SON établissement.
 * Trois requêtes au plus pour toutes les évaluations, quel que soit leur nombre.
 */
final class CoefficientsDesMatieres
{
    public const NEUTRE = 1.0;

    /**
     * @param  iterable<Evaluation>  $evaluations
     * @return array<int, float> le coefficient, par id d'évaluation
     */
    public function pour(iterable $evaluations): array
    {
        $liees = [];
        foreach ($evaluations as $evaluation) {
            $liees[$evaluation->id] = $evaluation;
        }
        if ($liees === []) {
            return [];
        }

        $matieres = $this->miroirs(Matiere::class, $liees, 'matiere_id', 'klassci_matiere_id');
        $classes = $this->miroirs(Classe::class, $liees, 'classe_id', 'klassci_classe_id');
        [$duProgramme, $deLaMatiere] = $this->coefficients(array_values(array_filter($matieres)), array_values(array_filter($classes)));

        $resultat = [];
        foreach ($liees as $id => $evaluation) {
            $matiere = $matieres[$id];
            $resultat[$id] = $duProgramme[$classes[$id].':'.$matiere] ?? $deLaMatiere[$matiere] ?? self::NEUTRE;
        }

        return $resultat;
    }

    /**
     * L'id LOCAL de la matière (ou de la classe) de chaque évaluation : la colonne locale, sinon
     * le miroir de l'identité KLASSCI dans l'établissement de l'évaluation ; 0 si aucun.
     *
     * @param  class-string<Matiere|Classe>  $modele
     * @param  array<int, Evaluation>  $evaluations
     * @return array<int, int>
     */
    private function miroirs(string $modele, array $evaluations, string $locale, string $klassci): array
    {
        $cles = [];
        foreach ($evaluations as $evaluation) {
            if ($evaluation->getAttribute($locale) === null && is_numeric($evaluation->getAttribute($klassci)) && is_int($evaluation->institution_id)) {
                $cles[$evaluation->institution_id][] = (int) $evaluation->getAttribute($klassci);
            }
        }

        $parKlassci = [];
        foreach ($cles as $etablissement => $ids) {
            foreach ($modele::query()->withoutGlobalScope('institution')->where('institution_id', $etablissement)->whereIn('klassci_id', array_unique($ids))->get(['id', 'klassci_id']) as $miroir) {
                $parKlassci[$etablissement.':'.$miroir->klassci_id] = (int) $miroir->id;
            }
        }

        $ids = [];
        foreach ($evaluations as $id => $evaluation) {
            $ids[$id] = is_numeric($evaluation->getAttribute($locale))
                ? (int) $evaluation->getAttribute($locale)
                : ($parKlassci[$evaluation->institution_id.':'.$evaluation->getAttribute($klassci)] ?? 0);
        }

        return $ids;
    }

    /**
     * @param  list<int>  $matieres
     * @param  list<int>  $classes
     * @return array{0: array<string, float>, 1: array<int, float>}
     */
    private function coefficients(array $matieres, array $classes): array
    {
        $duProgramme = [];
        if ($matieres !== [] && $classes !== []) {
            foreach (DB::table('classe_matiere')->whereIn('classe_id', $classes)->whereIn('matiere_id', $matieres)->whereNotNull('coefficient')->get(['classe_id', 'matiere_id', 'coefficient']) as $ligne) {
                $duProgramme[$ligne->classe_id.':'.$ligne->matiere_id] = (float) $ligne->coefficient;
            }
        }

        $deLaMatiere = [];
        if ($matieres !== []) {
            foreach (Matiere::query()->withoutGlobalScope('institution')->whereKey($matieres)->whereNotNull('coefficient')->get(['id', 'coefficient']) as $matiere) {
                $deLaMatiere[(int) $matiere->id] = (float) $matiere->coefficient;
            }
        }

        return [$duProgramme, $deLaMatiere];
    }
}
