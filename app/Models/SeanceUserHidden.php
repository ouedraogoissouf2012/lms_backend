<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

/**
 * Modèle pour gérer le masquage de séances par les étudiants
 *
 * Permet à chaque étudiant de masquer individuellement des séances
 * de sa vue personnelle sans affecter les autres utilisateurs
 */
class SeanceUserHidden extends Model
{
    use BelongsToInstitution;

    protected $table = 'seance_user_hidden';

    protected $fillable = [
        'seance_id',
        'user_id',
        'hidden_at',
        'institution_id',
    ];

    protected $casts = [
        'hidden_at' => 'datetime',
    ];

    /**
     * Relation vers la séance
     *
     * @return BelongsTo<Seance, $this>
     */
    public function seance(): BelongsTo
    {
        return $this->belongsTo(Seance::class);
    }

    /**
     * Relation vers l'utilisateur
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vérifier si une séance est masquée pour un utilisateur
     */
    public static function isHidden(int $seanceId, int $userId): bool
    {
        return self::where('seance_id', $seanceId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Masquer une séance pour un utilisateur
     */
    public static function hide(int $seanceId, int $userId): self
    {
        return self::firstOrCreate([
            'seance_id' => $seanceId,
            'user_id' => $userId,
        ], [
            'hidden_at' => now(),
        ]);
    }

    /**
     * Réafficher une séance pour un utilisateur
     */
    public static function unhide(int $seanceId, int $userId): bool
    {
        return self::where('seance_id', $seanceId)
            ->where('user_id', $userId)
            ->delete() > 0;
    }
}
