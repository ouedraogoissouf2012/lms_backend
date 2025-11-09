<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Notification
 *
 * Représente une notification pour un utilisateur
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    /**
     * Types de notifications disponibles
     */
    public const TYPE_LESSON_PUBLISHED = 'lesson_published';
    public const TYPE_FORUM_REPLY = 'forum_reply';
    public const TYPE_QUIZ_AVAILABLE = 'quiz_available';
    public const TYPE_GRADE_RECEIVED = 'grade_received';
    public const TYPE_LESSON_UPDATED = 'lesson_updated';
    public const TYPE_FORUM_SOLUTION = 'forum_solution';
    public const TYPE_QUIZ_DEADLINE = 'quiz_deadline';
    public const TYPE_VISIO_SCHEDULED = 'visio_scheduled';
    public const TYPE_VISIO_STARTING = 'visio_starting';
    public const TYPE_EVALUATION_APPROACHING = 'evaluation_approaching';

    /**
     * Relation: Utilisateur destinataire
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: Notifications non lues
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope: Notifications lues
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope: Notifications d'un utilisateur
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Par type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Récentes d'abord
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Marquer comme lue
     */
    public function markAsRead(): bool
    {
        if ($this->read_at !== null) {
            return true; // Déjà lue
        }

        return $this->update(['read_at' => now()]);
    }

    /**
     * Marquer comme non lue
     */
    public function markAsUnread(): bool
    {
        return $this->update(['read_at' => null]);
    }

    /**
     * Vérifier si la notification est lue
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * Vérifier si la notification est non lue
     */
    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Obtenir l'icône selon le type
     */
    public function getIcon(): string
    {
        return match($this->type) {
            self::TYPE_LESSON_PUBLISHED => 'mdi-book-open',
            self::TYPE_FORUM_REPLY => 'mdi-message-reply',
            self::TYPE_QUIZ_AVAILABLE => 'mdi-clipboard-list',
            self::TYPE_GRADE_RECEIVED => 'mdi-star',
            self::TYPE_LESSON_UPDATED => 'mdi-book-edit',
            self::TYPE_FORUM_SOLUTION => 'mdi-check-circle',
            self::TYPE_QUIZ_DEADLINE => 'mdi-clock-alert',
            self::TYPE_VISIO_SCHEDULED => 'mdi-video-outline',
            self::TYPE_VISIO_STARTING => 'mdi-video-check',
            self::TYPE_EVALUATION_APPROACHING => 'mdi-calendar-alert',
            default => 'mdi-bell',
        };
    }

    /**
     * Obtenir la couleur selon le type
     */
    public function getColor(): string
    {
        return match($this->type) {
            self::TYPE_LESSON_PUBLISHED => 'primary',
            self::TYPE_FORUM_REPLY => 'info',
            self::TYPE_QUIZ_AVAILABLE => 'warning',
            self::TYPE_GRADE_RECEIVED => 'success',
            self::TYPE_LESSON_UPDATED => 'primary',
            self::TYPE_FORUM_SOLUTION => 'success',
            self::TYPE_QUIZ_DEADLINE => 'error',
            self::TYPE_VISIO_SCHEDULED => 'info',
            self::TYPE_VISIO_STARTING => 'warning',
            self::TYPE_EVALUATION_APPROACHING => 'warning',
            default => 'secondary',
        };
    }

    /**
     * Obtenir le lien de l'action
     */
    public function getActionUrl(): ?string
    {
        $data = $this->data ?? [];

        return match($this->type) {
            self::TYPE_LESSON_PUBLISHED, self::TYPE_LESSON_UPDATED =>
                isset($data['lesson_id']) ? "/lessons/{$data['lesson_id']}" : null,

            self::TYPE_FORUM_REPLY, self::TYPE_FORUM_SOLUTION =>
                isset($data['topic_id']) ? "/forum/topics/{$data['topic_id']}" : null,

            self::TYPE_QUIZ_AVAILABLE, self::TYPE_GRADE_RECEIVED, self::TYPE_QUIZ_DEADLINE =>
                isset($data['quiz_id']) ? "/quizzes/{$data['quiz_id']}" : null,

            self::TYPE_VISIO_SCHEDULED, self::TYPE_VISIO_STARTING =>
                isset($data['seance_id']) ? "/seances/{$data['seance_id']}" : null,

            self::TYPE_EVALUATION_APPROACHING =>
                isset($data['evaluation_id']) ? "/student/evaluations/{$data['evaluation_id']}" : null,

            default => null,
        };
    }
}
