<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\LessonStatus;
use App\Observers\LessonObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Concern « publication » d'une {@see \App\Models\Lesson} (#522).
 *
 * Regroupe au même endroit tout l'état publié :
 *  - auto-enregistrement de l'observer d'invariant #481 via la convention
 *    `bootXxx` — UNE seule convention d'observer dans le projet (identique à
 *    {@see Auditable}), au lieu d'un `Model::observe()` dispersé dans
 *    `AppServiceProvider` ;
 *  - le scope `published()` et le prédicat `isPublished()`.
 *
 * Extraire ce concern garde `Lesson` sous la limite de 150 lignes (§5).
 *
 * @property LessonStatus $status
 * @property Carbon|null $published_at
 */
trait Publishable
{
    /**
     * Convention d'observer du projet : `boot{TraitName}()` auto-enregistré par
     * Eloquent quand le modèle `use` ce trait.
     */
    public static function bootPublishable(): void
    {
        static::observe(LessonObserver::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', LessonStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === LessonStatus::Published
            && $this->published_at !== null
            && $this->published_at->isPast();
    }
}
