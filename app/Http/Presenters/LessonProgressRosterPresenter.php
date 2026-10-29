<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\LessonProgress;
use Illuminate\Support\Collection;

/**
 * Projection JSON des progressions des élèves d'une leçon, vue du personnel.
 *
 * Allow-list EXPLICITE, sur le modèle de {@see UserListPresenter} : chaque élève
 * n'apparaît que par `{id, name}`, jamais par l'objet `User` sérialisé.
 * `User::$hidden` ne masque que le mot de passe et les jetons : l'e-mail, le
 * téléphone et `klassci_data` sortiraient sinon pour chaque élève de la leçon.
 *
 * Les notes personnelles de l'élève (`notes`) ne sortent pas non plus : elles
 * sont les siennes. L'avis qu'il laisse sur la leçon (`rating`, `feedback`)
 * reste, puisqu'il s'adresse à l'enseignant.
 *
 * Ajouter une colonne à `users` ou à `lesson_progress` ne peut donc pas élargir
 * cette réponse par inadvertance.
 */
final class LessonProgressRosterPresenter
{
    /**
     * @param  Collection<int, LessonProgress>  $progresses  `user` chargé.
     * @return list<array<string, mixed>>
     */
    public function present(Collection $progresses): array
    {
        $lignes = [];
        foreach ($progresses as $progress) {
            $lignes[] = $this->line($progress);
        }

        return $lignes;
    }

    /** @return array<string, mixed> */
    private function line(LessonProgress $progress): array
    {
        $eleve = $progress->user;

        return [
            'id' => $progress->id,
            'user_id' => $progress->user_id,
            'lesson_id' => $progress->lesson_id,
            'status' => $progress->status,
            'progress_percentage' => $progress->progress_percentage,
            'time_spent_minutes' => $progress->time_spent_minutes,
            'started_at' => $progress->started_at,
            'completed_at' => $progress->completed_at,
            'last_accessed_at' => $progress->last_accessed_at,
            'rating' => $progress->rating,
            'feedback' => $progress->feedback,
            'user' => $eleve === null ? null : ['id' => $eleve->id, 'name' => $eleve->name],
        ];
    }
}
