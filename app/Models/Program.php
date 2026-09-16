<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Programme — le contenu pédagogique versionnable (#800, `docs/LEXIQUE_V2.md`).
 *
 * Sans dates et sans tarif : ces deux-là vivent sur la Période. Un Programme
 * porteur d'un tarif obligerait à dupliquer le contenu pour chaque variante
 * tarifaire (ADR-711-06), et un Programme daté redeviendrait une promotion.
 *
 * Il est LU par référence, jamais copié : dupliquer une Période ne clone pas
 * ses chapitres (ADR-711-05).
 *
 * @property int $id
 * @property int $institution_id
 * @property string $titre
 * @property string|null $description
 * @property int $version
 */
class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use BelongsToInstitution, HasFactory, SoftDeletes;

    protected $fillable = [
        'institution_id',
        'titre',
        'description',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<TrainingSession, $this>
     */
    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
