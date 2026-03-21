<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use App\Models\Traits\BelongsToInstitution;

class LmsEnseignantCache extends Model
{
    use BelongsToInstitution;

    protected $table = 'lms_enseignants_cache';

    protected $fillable = [
        'enseignant_id',
        'data',
        'synced_at',
        'expires_at',
        'institution_id',
    ];

    protected $casts = [
        'data' => 'array',
        'synced_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Vérifie si le cache est encore valide
     */
    public function isValid(): bool
    {
        return $this->expires_at && $this->expires_at->isFuture();
    }

    /**
     * Vérifie si le cache est expiré
     */
    public function isExpired(): bool
    {
        return !$this->isValid();
    }

    /**
     * Récupère un enseignant depuis le cache ou null si expiré
     */
    public static function getValid(int $enseignantId): ?self
    {
        $cache = self::where('enseignant_id', $enseignantId)->first();

        if (!$cache || $cache->isExpired()) {
            return null;
        }

        return $cache;
    }

    /**
     * Stocke ou met à jour le cache pour un enseignant
     */
    public static function store(int $enseignantId, array $data, int $ttlMinutes = 10): self
    {
        return self::updateOrCreate(
            ['enseignant_id' => $enseignantId],
            [
                'data' => $data,
                'synced_at' => Carbon::now(),
                'expires_at' => Carbon::now()->addMinutes($ttlMinutes),
            ]
        );
    }

    /**
     * Nettoie les caches expirés
     */
    public static function cleanExpired(): int
    {
        return self::where('expires_at', '<', Carbon::now())->delete();
    }
}
