<?php

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Carbon\Carbon;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model Notification
 *
 * Représente une notification pour un utilisateur.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $title
 * @property string $message
 * @property array<string, mixed>|null $data
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use BelongsToInstitution, HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'read_at',
        'institution_id',
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

    /** #1681 : la gestion a tranché un justificatif d'absence de l'élève. */
    public const TYPE_ABSENCE_JUSTIFICATION_DECIDED = 'absence_justification_decided';

    /** #1650 : une annonce du personnel à toute une classe (`data.annonce_id`). */
    public const TYPE_ANNONCE = 'annonce';

    /** Les types connus : la liste blanche du filtre `type` de `GET /notifications` (front#690). */
    public const TYPES = [
        self::TYPE_LESSON_PUBLISHED, self::TYPE_FORUM_REPLY, self::TYPE_QUIZ_AVAILABLE, self::TYPE_GRADE_RECEIVED,
        self::TYPE_LESSON_UPDATED, self::TYPE_FORUM_SOLUTION, self::TYPE_QUIZ_DEADLINE, self::TYPE_VISIO_SCHEDULED,
        self::TYPE_VISIO_STARTING, self::TYPE_EVALUATION_APPROACHING, self::TYPE_ANNONCE,
        self::TYPE_ABSENCE_JUSTIFICATION_DECIDED,
    ];

    /**
     * Relation: Utilisateur destinataire
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: Notifications non lues
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope: Notifications lues
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope: Notifications d'un utilisateur
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Par type
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Récentes d'abord
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
