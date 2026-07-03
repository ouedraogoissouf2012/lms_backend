<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

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
 * @property \Carbon\Carbon|null $read_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Notification extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationFactory> */
    use HasFactory, BelongsToInstitution;

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
     * @param  \Illuminate\Database\Eloquent\Builder<Notification>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Notification>
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope: Notifications lues
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Notification>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Notification>
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope: Notifications d'un utilisateur
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Notification>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Notification>
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Par type
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Notification>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Notification>
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Récentes d'abord
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Notification>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Notification>
     */
    public function scopeRecent($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

}
