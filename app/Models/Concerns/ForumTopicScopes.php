<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\ForumTopic;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les portées de recherche des sujets du forum ({@see ForumTopic}) : état, activité et
 * rattachement (matière, classe, leçon).
 *
 * Extraites de `ForumTopic` telles quelles (#1231) : le reformatage Pint remettait une ligne
 * entre chaque portée, et le modèle dépassait la limite de 150 lignes.
 */
trait ForumTopicScopes
{
    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeOpen(Builder $query)
    {
        return $query->where('status', 'open');
    }

    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopePinned(Builder $query)
    {
        return $query->where('status', 'pinned');
    }

    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeResolved(Builder $query)
    {
        return $query->where('is_resolved', true);
    }

    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeByActivity(Builder $query)
    {
        return $query->orderBy('last_activity_at', 'desc');
    }

    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeForMatiere(Builder $query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }

    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeForClasse(Builder $query, int $classeId)
    {
        return $query->where('classe_id', $classeId);
    }

    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeForLesson(Builder $query, int $lessonId)
    {
        return $query->where('lesson_id', $lessonId);
    }
}
