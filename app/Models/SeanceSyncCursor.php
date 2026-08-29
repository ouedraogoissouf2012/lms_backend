<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Position de reprise de la synchronisation des séances KLASSCI (#582).
 *
 * Délibérément HORS `BelongsToInstitution` : c'est une position de balayage
 * GLOBALE, qui traverse tous les tenants dans l'ordre `(institution_id, id)`.
 * La scoper par tenant la rendrait inopérante (chaque tenant aurait sa propre
 * position, et la frontière de tenant — qui déclenche l'archivage — n'existerait
 * plus).
 *
 * Modèle de données pur (§5 : relations, casts, scopes) — toute la logique vit
 * dans {@see \App\Services\Seances\Sync\Cursor\EloquentSeanceSyncCursorStore}.
 *
 * @property string $name
 * @property int|null $last_institution_id
 * @property int|null $last_user_id
 * @property \Illuminate\Support\Carbon $cycle_started_at
 * @property array<int, int> $tainted_institution_ids
 */
class SeanceSyncCursor extends Model
{
    /** Nom du curseur unique de la sync des séances (unicité garantie en base). */
    public const KLASSCI_SEANCES = 'klassci_seances';

    protected $table = 'seance_sync_cursors';

    protected $fillable = [
        'name',
        'last_institution_id',
        'last_user_id',
        'cycle_started_at',
        'tainted_institution_ids',
    ];

    protected $casts = [
        'last_institution_id' => 'integer',
        'last_user_id' => 'integer',
        'cycle_started_at' => 'datetime',
        'tainted_institution_ids' => 'array',
    ];
}
