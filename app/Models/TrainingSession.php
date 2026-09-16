<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TrainingSessionPhase;
use App\Enums\TrainingSessionStatus;
use App\Models\Traits\BelongsToInstitution;
use Database\Factories\TrainingSessionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Période — le parcours DATÉ (#800, `docs/LEXIQUE_V2.md`).
 *
 * C'est l'entité racine du monde autonome : elle porte les dates, le tarif,
 * les inscriptions et l'assiduité. Le Programme, lui, ne porte que le contenu.
 *
 * ## `status` est stocké, `phase` est dérivée
 *
 * Le statut traduit une décision humaine — publier, annuler, clore. La phase
 * n'est qu'une lecture des cinq dates : elle n'a donc **aucune colonne**, et
 * l'accessor ci-dessous est sa seule source. Stocker les deux imposerait un job
 * nocturne dont l'étiquette finirait par diverger des dates en silence
 * (ADR-711-04).
 *
 * L'ordre des cinq dates est garanti par la base, pas seulement par la
 * validation HTTP — un import ou une commande la contourneraient.
 *
 * @property int $id
 * @property int $institution_id
 * @property int $program_id
 * @property string $libelle
 * @property TrainingSessionStatus $status
 * @property Carbon|null $enrollment_opens_at
 * @property Carbon|null $enrollment_closes_at
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property Carbon|null $certificate_available_at
 * @property int|null $min_enrollments
 * @property int|null $tarif Plus petite unité monétaire ; jamais un flottant.
 * @property string|null $devise
 * @property int|null $heures_stagiaires
 * @property int|null $commanditaire_id Réservé (ADR-711-06), aucun écran en V2.
 * @property-read TrainingSessionPhase $phase Dérivée des dates, jamais stockée.
 */
class TrainingSession extends Model
{
    /** @use HasFactory<TrainingSessionFactory> */
    use BelongsToInstitution, HasFactory, SoftDeletes;

    protected $fillable = [
        'institution_id',
        'program_id',
        'libelle',
        'status',
        'enrollment_opens_at',
        'enrollment_closes_at',
        'starts_on',
        'ends_on',
        'certificate_available_at',
        'min_enrollments',
        'tarif',
        'devise',
        'heures_stagiaires',
        'commanditaire_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TrainingSessionStatus::class,
            'enrollment_opens_at' => 'date',
            'enrollment_closes_at' => 'date',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'certificate_available_at' => 'date',
            'min_enrollments' => 'integer',
            'tarif' => 'integer',
            'heures_stagiaires' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return HasMany<Creneau, $this>
     */
    public function creneaux(): HasMany
    {
        return $this->hasMany(Creneau::class);
    }

    /**
     * La phase, LUE des dates — jamais écrite.
     *
     * @return Attribute<TrainingSessionPhase, never>
     */
    protected function phase(): Attribute
    {
        return Attribute::make(
            get: fn (): TrainingSessionPhase => TrainingSessionPhase::depuisLesDates(
                $this->enrollment_opens_at,
                $this->enrollment_closes_at,
                $this->starts_on,
                $this->ends_on,
            ),
        );
    }
}
