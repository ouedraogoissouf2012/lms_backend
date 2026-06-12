<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use App\Models\Chapter;
use App\Models\KnowledgeCheck;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Service CRUD pour les quiz « Testez vos connaissances » (KnowledgeCheck).
 *
 * Extrait verbatim depuis `KnowledgeCheckController` (447 lignes → split SRP).
 *
 * ## Périmètre
 *
 * - Listing par chapitre (filtré actif + ordonné + enrichi par user).
 * - Détails + récupération du quiz actif par chapitre.
 * - Create/Update/Delete avec vérification d'autorisation (admin OU
 *   enseignant propriétaire du chapitre).
 *
 * ## DI strict (§1.6 D)
 *
 * Pure logique métier — pas de Facade, pas de `app()`. Le service est
 * stateless ; il opère uniquement sur les models passés en argument.
 *
 * @see app/Http/Controllers/API/KnowledgeCheckCrudController.php
 */
final class KnowledgeCheckCrudService
{
    public function __construct(private readonly KnowledgeCheckAccessService $access)
    {
    }

    /**
     * Liste les quiz d'un chapitre avec enrichissement utilisateur.
     *
     * @return Collection<int, KnowledgeCheck>
     */
    public function listForChapter(int $chapterId, int $userId): Collection
    {
        $quizzes = KnowledgeCheck::forChapter($chapterId)
            ->active()
            ->ordered()
            ->get();

        return $quizzes->map(function (KnowledgeCheck $quiz) use ($userId): KnowledgeCheck {
            return $this->enrichWithUserStats($quiz, $userId);
        });
    }

    /**
     * Récupère le quiz actif d'un chapitre (1er trouvé) avec enrichissement.
     *
     * Retourne `null` si aucun quiz actif n'est attaché au chapitre.
     */
    public function findActiveForChapter(int $chapterId, int $userId): ?KnowledgeCheck
    {
        Chapter::findOrFail($chapterId);

        $quiz = KnowledgeCheck::where('chapter_id', $chapterId)
            ->where('is_active', true)
            ->first();

        if ($quiz === null) {
            return null;
        }

        return $this->enrichWithUserStats($quiz, $userId);
    }

    /**
     * Détail d'un quiz avec relation chapter + enrichissement utilisateur
     * étendu (incluant le count des tentatives).
     */
    public function findById(string $id, int $userId): KnowledgeCheck
    {
        $quiz = KnowledgeCheck::with('chapter')->findOrFail($id);

        $quiz->user_passed = $this->access->isPassedByUser($quiz, $userId);
        $quiz->user_best_score = $this->access->bestScore($quiz, $userId);
        $quiz->can_attempt = $this->access->canAttempt($quiz, $userId);
        $quiz->attempts_count = $quiz->attempts()->where('user_id', $userId)->count();

        return $quiz;
    }

    /**
     * Crée un quiz. Vérifie que l'utilisateur a le droit (admin ou enseignant
     * propriétaire du chapitre).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $user): KnowledgeCheck
    {
        $chapter = Chapter::findOrFail($data['chapter_id']);
        $this->assertCanManageChapter($user, $chapter);

        // Scope tenant explicite (defense en profondeur, fix E2E #211 flow 2).
        $data['institution_id'] = $user->institution_id;

        return KnowledgeCheck::create($data);
    }

    /**
     * Met à jour un quiz. Vérifie l'autorisation et retourne le quiz frais.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data, User $user): KnowledgeCheck
    {
        $quiz = KnowledgeCheck::findOrFail($id);
        $this->assertCanManageChapter($user, $quiz->chapter);

        $quiz->update($data);

        /** @var KnowledgeCheck $fresh */
        $fresh = $quiz->fresh();

        return $fresh;
    }

    /**
     * Supprime un quiz. Vérifie l'autorisation.
     */
    public function delete(string $id, User $user): void
    {
        $quiz = KnowledgeCheck::findOrFail($id);
        $this->assertCanManageChapter($user, $quiz->chapter);

        $quiz->delete();
    }

    /**
     * Vérifie qu'un user peut gérer le chapitre (admin OU enseignant
     * propriétaire). Lève `\RuntimeException` sinon — le controller traduit
     * en réponse 403.
     */
    private function assertCanManageChapter(User $user, ?Chapter $chapter): void
    {
        if ($chapter === null) {
            throw new \RuntimeException('Chapitre introuvable pour ce quiz');
        }

        if (! $user->isAdmin() && $chapter->enseignant_id !== $user->id) {
            throw new \DomainException('Non autorise a modifier ce quiz');
        }
    }

    /**
     * Ajoute les attributs dynamiques (`user_passed`, `user_best_score`,
     * `can_attempt`, `questions_count`) au model — pratique pré-existante
     * du controller. PHPStan râle (ces props ne sont pas déclarées) mais on
     * conserve le comportement runtime exact.
     */
    private function enrichWithUserStats(KnowledgeCheck $quiz, int $userId): KnowledgeCheck
    {
        $quiz->user_passed = $this->access->isPassedByUser($quiz, $userId);
        $quiz->user_best_score = $this->access->bestScore($quiz, $userId);
        $quiz->can_attempt = $this->access->canAttempt($quiz, $userId);
        $quiz->questions_count = $quiz->questions_count;

        return $quiz;
    }
}
