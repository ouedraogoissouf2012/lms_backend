<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée du journal d'audit (#215). Append-only — relations/casts/scopes
 * uniquement (§5). L'écriture passe par AuditLogger, la lecture par
 * AuditLogController.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $institution_id
 * @property string $action
 */
class AuditLog extends Model
{
    use HasFactory;

    // Append-only : pas d'updated_at (les logs ne sont jamais modifiés).
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'institution_id', 'action',
        'auditable_type', 'auditable_id',
        'before', 'after', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    /** Acteur de l'action (null si échec d'auth ou job système). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: filtre par action normalisée.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * Scope: filtre par acteur.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: plus récents d'abord.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }
}
