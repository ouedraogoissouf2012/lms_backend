<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Banque;

use App\Exceptions\BusinessException;
use App\Models\CategorieDeQuestions;
use App\Models\Matiere;
use App\Models\QuestionDeBanque;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * #1702 (D1) : la banque de questions, par enseignant et par matière.
 *
 * - L'ENSEIGNANT en est le seul auteur : il crée, renomme et supprime ses catégories, il y écrit
 *   ses questions (la décision du user sur les leçons : seul l'enseignant crée et gère).
 * - Le coordinateur et l'admin de l'école la LISENT toute ; leurs écritures sont refusées (403
 *   `question_bank_owner_only`).
 * - La catégorie d'un AUTRE enseignant est introuvable pour un enseignant, comme celle d'une
 *   autre école pour tous : 404, du même corps (la règle de #1782).
 * - Toujours bornée explicitement à l'établissement du lecteur.
 */
final class BanqueDeQuestions
{
    /** @return Collection<int, CategorieDeQuestions> */
    public function categories(User $lecteur, ?int $matiere): Collection
    {
        return CategorieDeQuestions::query()->withoutGlobalScope('institution')
            ->where('institution_id', $lecteur->institution_id)
            ->when(! $lecteur->isManager(), static fn (Builder $q) => $q->where('enseignant_id', $lecteur->id))
            ->when($matiere !== null, static fn (Builder $q) => $q->where('matiere_id', $matiere))
            ->withCount('questions')
            ->orderBy('libelle')
            ->get();
    }

    /** La catégorie, si le lecteur la voit ; introuvable sinon. */
    public function categorie(int $id, User $lecteur): CategorieDeQuestions
    {
        $categorie = CategorieDeQuestions::query()->withoutGlobalScope('institution')
            ->where('institution_id', $lecteur->institution_id)
            ->whereKey($id)
            ->first();

        if (! $categorie instanceof CategorieDeQuestions || (! $lecteur->isManager() && $categorie->enseignant_id !== $lecteur->id)) {
            throw new NotFoundHttpException('Catégorie introuvable.');
        }

        return $categorie;
    }

    public function creerCategorie(User $auteur, int $matiere, string $libelle): CategorieDeQuestions
    {
        self::auteurDUneBanque($auteur);
        $existe = Matiere::query()->withoutGlobalScope('institution')
            ->where('institution_id', $auteur->institution_id)->whereKey($matiere)->exists();
        if (! $existe) {
            throw new BusinessException('Matière introuvable dans cet établissement.', 404, null, 'subject_out_of_scope');
        }

        return $this->enregistrer(new CategorieDeQuestions([
            'institution_id' => (int) $auteur->institution_id,
            'enseignant_id' => (int) $auteur->id,
            'matiere_id' => $matiere,
        ]), $libelle);
    }

    public function renommerCategorie(CategorieDeQuestions $categorie, User $auteur, string $libelle): CategorieDeQuestions
    {
        self::proprietaire($categorie, $auteur);

        return $this->enregistrer($categorie, $libelle);
    }

    public function supprimerCategorie(CategorieDeQuestions $categorie, User $auteur): void
    {
        self::proprietaire($categorie, $auteur);
        $categorie->delete();
    }

    /** @param  array<string, mixed>  $champs  validés par `ReglesDUneQuestion` */
    public function ajouterQuestion(CategorieDeQuestions $categorie, User $auteur, array $champs): QuestionDeBanque
    {
        self::proprietaire($categorie, $auteur);

        return QuestionDeBanque::query()->create(self::champs($champs) + [
            'institution_id' => $categorie->institution_id,
            'categorie_id' => $categorie->id,
        ]);
    }

    /** La question, si le lecteur voit sa catégorie ; introuvable sinon. */
    public function question(int $id, User $lecteur): QuestionDeBanque
    {
        $question = QuestionDeBanque::query()->withoutGlobalScope('institution')
            ->where('institution_id', $lecteur->institution_id)->whereKey($id)->first();
        if (! $question instanceof QuestionDeBanque) {
            throw new NotFoundHttpException('Question introuvable.');
        }
        $this->categorie($question->categorie_id, $lecteur);

        return $question;
    }

    /** @param  array<string, mixed>  $champs */
    public function modifierQuestion(QuestionDeBanque $question, User $auteur, array $champs): QuestionDeBanque
    {
        self::proprietaire($this->categorie($question->categorie_id, $auteur), $auteur);
        $question->fill(self::champs($champs))->save();

        return $question;
    }

    public function supprimerQuestion(QuestionDeBanque $question, User $auteur): void
    {
        self::proprietaire($this->categorie($question->categorie_id, $auteur), $auteur);
        $question->delete();
    }

    private function enregistrer(CategorieDeQuestions $categorie, string $libelle): CategorieDeQuestions
    {
        try {
            $categorie->fill(['libelle' => trim($libelle)])->save();
        } catch (UniqueConstraintViolationException) {
            throw new BusinessException('Vous avez déjà une catégorie de ce nom pour cette matière.', 422, null, 'question_category_duplicate');
        }

        return $categorie;
    }

    /**
     * @param  array<string, mixed>  $champs
     * @return array<string, mixed>
     */
    private static function champs(array $champs): array
    {
        return [
            'question' => $champs['question'] ?? '',
            'type' => $champs['type'] ?? 'qcm',
            'points' => $champs['points'] ?? 1,
            'options' => $champs['options'] ?? null,
            'correct_answers' => $champs['correct_answers'] ?? null,
            'explanation' => $champs['explanation'] ?? null,
        ];
    }

    private static function auteurDUneBanque(User $auteur): void
    {
        if (! $auteur->isTeacher()) {
            throw new BusinessException('Seul un enseignant écrit dans sa banque de questions.', 403, null, 'question_bank_owner_only');
        }
    }

    private static function proprietaire(CategorieDeQuestions $categorie, User $auteur): void
    {
        self::auteurDUneBanque($auteur);
        if ($categorie->enseignant_id !== $auteur->id) {
            throw new BusinessException('Seul l\'auteur de la banque y écrit.', 403, null, 'question_bank_owner_only');
        }
    }
}
