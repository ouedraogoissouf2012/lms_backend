<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Evaluation;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\EcrireUneCategorieDeQuestionsRequest;
use App\Http\Requests\EcrireUneQuestionDeBanqueRequest;
use App\Models\CategorieDeQuestions;
use App\Models\QuestionDeBanque;
use App\Services\Evaluation\Banque\BanqueDeQuestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1702 (D1) : `/api/banque/…`, la banque de questions (catégories par enseignant et matière, et
 * leurs questions). Les droits sont ceux de {@see BanqueDeQuestions} : l'enseignant écrit sa
 * banque ; le coordinateur et l'admin la lisent ; la catégorie d'un autre enseignant ou d'une
 * autre école est introuvable (404).
 */
final class BanqueDeQuestionsController extends AuthenticatedController
{
    public function __construct(private readonly BanqueDeQuestions $banque) {}

    public function categories(Request $request): JsonResponse
    {
        $matiere = $request->query('matiere_id');

        return $this->successResponse(array_values($this->banque
            ->categories($this->authenticatedUser($request), is_numeric($matiere) ? (int) $matiere : null)
            ->map(static fn (CategorieDeQuestions $categorie): array => self::categorie($categorie))->all()));
    }

    public function creerCategorie(EcrireUneCategorieDeQuestionsRequest $request): JsonResponse
    {
        return $this->ecrire(fn (): array => self::categorie(
            $this->banque->creerCategorie($this->authenticatedUser($request), $request->matiere(), $request->libelle())
        ), 201);
    }

    public function renommerCategorie(EcrireUneCategorieDeQuestionsRequest $request, int $id): JsonResponse
    {
        $auteur = $this->authenticatedUser($request);

        return $this->ecrire(fn (): array => self::categorie(
            $this->banque->renommerCategorie($this->banque->categorie($id, $auteur), $auteur, $request->libelle())
        ));
    }

    public function supprimerCategorie(Request $request, int $id): JsonResponse
    {
        $auteur = $this->authenticatedUser($request);

        return $this->ecrire(function () use ($id, $auteur): array {
            $this->banque->supprimerCategorie($this->banque->categorie($id, $auteur), $auteur);

            return ['id' => $id];
        });
    }

    public function questions(Request $request, int $id): JsonResponse
    {
        $categorie = $this->banque->categorie($id, $this->authenticatedUser($request));

        return $this->successResponse(array_values($categorie->questions()->get()
            ->map(static fn (QuestionDeBanque $question): array => self::question($question))->all()));
    }

    public function ajouterQuestion(EcrireUneQuestionDeBanqueRequest $request, int $id): JsonResponse
    {
        $auteur = $this->authenticatedUser($request);

        return $this->ecrire(fn (): array => self::question(
            $this->banque->ajouterQuestion($this->banque->categorie($id, $auteur), $auteur, $request->champs())
        ), 201);
    }

    public function modifierQuestion(EcrireUneQuestionDeBanqueRequest $request, int $id): JsonResponse
    {
        $auteur = $this->authenticatedUser($request);

        return $this->ecrire(fn (): array => self::question(
            $this->banque->modifierQuestion($this->banque->question($id, $auteur), $auteur, $request->champs())
        ));
    }

    public function supprimerQuestion(Request $request, int $id): JsonResponse
    {
        $auteur = $this->authenticatedUser($request);

        return $this->ecrire(function () use ($id, $auteur): array {
            $this->banque->supprimerQuestion($this->banque->question($id, $auteur), $auteur);

            return ['id' => $id];
        });
    }

    /** @param  callable(): array<string, mixed>  $geste */
    private function ecrire(callable $geste, int $statut = 200): JsonResponse
    {
        try {
            return $this->successResponse($geste(), status: $statut);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }

    /** @return array{id: int, matiere_id: int, enseignant_id: int, libelle: string, nombre_de_questions: int} */
    private static function categorie(CategorieDeQuestions $categorie): array
    {
        $nombre = $categorie->getAttribute('questions_count');

        return [
            'id' => $categorie->id,
            'matiere_id' => $categorie->matiere_id,
            'enseignant_id' => $categorie->enseignant_id,
            'libelle' => $categorie->libelle,
            'nombre_de_questions' => is_numeric($nombre) ? (int) $nombre : $categorie->questions()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private static function question(QuestionDeBanque $question): array
    {
        return [
            'id' => $question->id,
            'categorie_id' => $question->categorie_id,
            'question' => $question->question,
            'type' => $question->type,
            'points' => (float) $question->points,
            'options' => $question->options,
            'correct_answers' => $question->correct_answers,
            'explanation' => $question->explanation,
        ];
    }
}
