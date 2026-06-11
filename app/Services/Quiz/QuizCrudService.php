<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Orchestrateur métier du CRUD quiz (split-27/quiz-crud).
 *
 * Extrait de `QuizCrudController` (218l) — filtres, tris, chargements et
 * règles de visibilité étudiant/enseignant vivent ici. Le controller devient
 * un thin shell conforme §5 (≤200l) et §1.1 (services ≤300l).
 *
 * DI strict §1.6 D : aucun `app(...)`. Aucune dépendance injectée nécessaire
 * (les opérations Eloquent suffisent), mais le constructeur reste explicite
 * pour rester conforme au pattern projet et permettre l'ajout futur de
 * `LoggerInterface` sans casser l'API publique.
 *
 * Sémantique de retour :
 *   - `list()`     : paginateur enrichi (infos tentatives par quiz/user)
 *   - `create()`   : modèle créé, relations chargées
 *   - `show()`     : tableau normalisé `{status, success, message, data}`
 *                    pour porter le cas "non disponible" en 403 sans
 *                    exception (parité 1:1 avec l'ancien controller)
 *   - `update()`   : modèle rechargé avec relations
 *   - `delete()`   : booléen
 *   - `publish()`  : tableau normalisé pour porter le cas "sans questions"
 *                    en 422 sans exception
 *
 * @see app/Http/Controllers/API/Quiz/QuizCrudController.php
 */
final class QuizCrudService
{
    public function __construct(private readonly QuizAccessService $access)
    {
    }

    /**
     * Liste paginée filtrée + enrichissement par utilisateur.
     *
     * Comportement préservé verbatim de `QuizCrudController::index` :
     *   - Étudiants : restreint à `available()`.
     *   - Filtres optionnels : `lesson_id`, `matiere_id`, `classe_id`,
     *     `status`, `type`.
     *   - Tri : `recent` (défaut), `title`, `popular`.
     *   - Enrichissement par item : `user_attempts_count`,
     *     `user_can_attempt`, `user_best_attempt`.
     *
     * @param  array{
     *     lesson_id?:int|string,
     *     matiere_id?:int|string,
     *     classe_id?:int|string,
     *     status?:string,
     *     type?:string,
     *     sort?:string,
     *     per_page?:int|string
     * }  $filters
     * @return LengthAwarePaginator<int,Quiz>
     */
    public function list(User $user, array $filters): LengthAwarePaginator
    {
        $query = Quiz::with([
            'creator:id,name,email,role',
            'matiere:id,libelle',
            'classe:id,libelle',
        ]);

        if ($user->isStudent()) {
            $query->available();
        }

        if (isset($filters['lesson_id'])) {
            $query->forLesson((int) $filters['lesson_id']);
        }

        if (isset($filters['matiere_id'])) {
            $query->forMatiere((int) $filters['matiere_id']);
        }

        if (isset($filters['classe_id'])) {
            $query->forClasse((int) $filters['classe_id']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $sortBy = $filters['sort'] ?? 'recent';
        match ($sortBy) {
            'title'   => $query->orderBy('title', 'asc'),
            'popular' => $query->orderBy('attempts_count', 'desc'),
            default   => $query->orderBy('created_at', 'desc'),
        };

        $perPage = (int) ($filters['per_page'] ?? 15);
        $quizzes = $query->paginate($perPage);

        $quizzes->getCollection()->transform(function (Quiz $quiz) use ($user): Quiz {
            $quiz->user_attempts_count = $this->access->attemptsCountForUser($quiz, $user->id);
            $quiz->user_can_attempt    = $this->access->canUserAttempt($quiz, $user->id);
            $quiz->user_best_attempt   = $this->access->bestAttemptForUser($quiz, $user->id);

            return $quiz;
        });

        return $quizzes;
    }

    /**
     * Création d'un quiz par un enseignant.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(array $data, User $creator): Quiz
    {
        $data['created_by'] = $creator->id;

        // Scope tenant explicite (defense en profondeur, fix E2E #211 flow 2).
        $data['institution_id'] = $creator->institution_id;
        $quiz = Quiz::create($data);
        $quiz->load('creator', 'matiere', 'classe');

        return $quiz;
    }

    /**
     * Détails d'un quiz avec règles de visibilité role-based.
     *
     * Retourne `success=false` (403) si étudiant + quiz non disponible —
     * pas d'exception, le controller transcrit en JsonResponse.
     *
     * @return array{status:int,success:bool,message:?string,data:?Quiz}
     */
    public function show(Quiz $quiz, User $user): array
    {
        $quiz->load([
            'creator:id,name,email,role',
            'matiere:id,libelle',
            'classe:id,libelle',
            'lesson:id,title',
        ]);

        if ($user->isStudent() && ! $this->access->isAvailable($quiz)) {
            return [
                'status'  => 403,
                'success' => false,
                'message' => 'Ce quiz n\'est pas disponible',
                'data'    => null,
            ];
        }

        if ($user->isTeacher() || $user->isAdmin()) {
            $quiz->load('questions.answers');
        } else {
            // Étudiants : questions sans révéler les bonnes réponses
            // (`is_correct` etc. exclus du SELECT).
            $quiz->load(['questions' => function ($query): void {
                $query->with(['answers' => function ($q): void {
                    $q->select('id', 'question_id', 'answer_text', 'order');
                }]);
            }]);
        }

        $quiz->user_attempts_count = $this->access->attemptsCountForUser($quiz, $user->id);
        $quiz->user_can_attempt    = $this->access->canUserAttempt($quiz, $user->id);
        $quiz->user_best_attempt   = $this->access->bestAttemptForUser($quiz, $user->id);
        $quiz->user_latest_attempt = $this->access->latestAttemptForUser($quiz, $user->id);

        return [
            'status'  => 200,
            'success' => true,
            'message' => null,
            'data'    => $quiz,
        ];
    }

    /**
     * Mise à jour d'un quiz. Retourne le modèle rechargé (relations fraîches).
     *
     * @param  array<string,mixed>  $data
     */
    public function update(Quiz $quiz, array $data): Quiz
    {
        $quiz->update($data);
        $quiz->refresh();
        $quiz->load(['creator', 'matiere', 'classe']);

        return $quiz;
    }

    /**
     * Suppression d'un quiz.
     */
    public function delete(Quiz $quiz): bool
    {
        return (bool) $quiz->delete();
    }

    /**
     * Publication d'un quiz. Refuse (422) si aucune question.
     *
     * @return array{status:int,success:bool,message:string,data:?Quiz}
     */
    public function publish(Quiz $quiz): array
    {
        if ($quiz->questions()->count() === 0) {
            return [
                'status'  => 422,
                'success' => false,
                'message' => 'Impossible de publier un quiz sans questions',
                'data'    => null,
            ];
        }

        $this->access->publish($quiz);

        return [
            'status'  => 200,
            'success' => true,
            'message' => 'Quiz publié',
            'data'    => $quiz,
        ];
    }
}
