<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Tirage;

use App\Exceptions\BusinessException;
use App\Models\CategorieDeQuestions;
use App\Models\Evaluation;
use App\Models\QuestionDeBanque;
use App\Models\TirageDEvaluation;
use App\Services\Evaluation\Student\CoefficientsDesMatieres;
use Illuminate\Support\Facades\DB;

/**
 * #1702 (D3) : les règles de tirage d'une évaluation — « tirer N questions de la catégorie C ».
 *
 * - Elles se définissent AVANT la publication (après, le vivier est copié et les copies en
 *   dépendent) : 409 `draw_rules_locked`.
 * - Une catégorie doit être de l'AUTEUR de l'évaluation et de SA matière (id local, dans les deux
 *   volets) : 422 `question_category_out_of_scope`, qui nomme la règle fautive.
 * - Chaque règle dit combien de questions sa catégorie offre, et avertit sous 2 × N : la banque
 *   d'au moins deux fois le nombre de questions de l'épreuve (Blackboard, cité par l'issue). Ce
 *   n'est pas un refus ; le refus (moins que N, points inégaux) vient à la publication.
 */
final class TiragesDUneEvaluation
{
    public function __construct(private readonly CoefficientsDesMatieres $matieres) {}

    /**
     * @param  list<array{categorie_id: int, nombre: int}>  $regles
     * @return list<array{id: int, categorie_id: int|null, libelle: string|null, nombre: int, disponibles: int, avertissement: string|null}>
     */
    public function definir(Evaluation $evaluation, array $regles): array
    {
        if ($evaluation->is_published) {
            throw new BusinessException('Les tirages se définissent avant la publication de l\'évaluation.', 409, null, 'draw_rules_locked');
        }

        $matiere = $this->matieres->matieresLocales([$evaluation])[$evaluation->id] ?? 0;
        $categories = CategorieDeQuestions::query()->withoutGlobalScope('institution')
            ->where('institution_id', $evaluation->institution_id)
            ->whereKey(array_column($regles, 'categorie_id'))
            ->get()->keyBy('id');
        foreach ($regles as $rang => $regle) {
            $categorie = $categories->get($regle['categorie_id']);
            if (! $categorie instanceof CategorieDeQuestions || $categorie->enseignant_id !== $evaluation->enseignant_id || $categorie->matiere_id !== $matiere) {
                throw new BusinessException('Une catégorie du tirage n\'est pas de l\'auteur et de la matière de l\'évaluation (tirage '.($rang + 1).').', 422, null, 'question_category_out_of_scope');
            }
        }

        DB::transaction(function () use ($evaluation, $regles): void {
            $evaluation->tirages()->delete();
            foreach ($regles as $regle) {
                TirageDEvaluation::query()->create([
                    'institution_id' => $evaluation->institution_id,
                    'evaluation_id' => $evaluation->id,
                    'categorie_id' => $regle['categorie_id'],
                    'nombre' => $regle['nombre'],
                ]);
            }
        });

        return $this->lire($evaluation);
    }

    /** @return list<array{id: int, categorie_id: int|null, libelle: string|null, nombre: int, disponibles: int, avertissement: string|null}> */
    public function lire(Evaluation $evaluation): array
    {
        $lignes = [];
        foreach ($evaluation->tirages()->with('categorie')->get() as $tirage) {
            $disponibles = $tirage->categorie_id === null ? 0 : QuestionDeBanque::query()->withoutGlobalScope('institution')
                ->where('institution_id', $tirage->institution_id)->where('categorie_id', $tirage->categorie_id)->count();
            $lignes[] = [
                'id' => $tirage->id,
                'categorie_id' => $tirage->categorie_id,
                'libelle' => $tirage->categorie?->libelle,
                'nombre' => $tirage->nombre,
                'disponibles' => $disponibles,
                'avertissement' => $disponibles < 2 * $tirage->nombre
                    ? 'La catégorie offre '.$disponibles.' question(s) pour '.$tirage->nombre.' à tirer : au moins le double est recommandé.'
                    : null,
            ];
        }

        return $lignes;
    }
}
