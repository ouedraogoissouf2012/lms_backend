<?php

namespace App\Models;

use App\Enums\InstitutionMode;
use Database\Factories\InstitutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tenant du SaaS multi-institution.
 *
 * @property-read string|null $klassci_api_token Alias accessor de la colonne chiffrée `klassci_api_token_encrypted`.
 * @property Carbon|null $deleted_at Soft delete (#567) — suppression LOGIQUE
 *                                   et réversible ; config du tenant préservée, restaurable.
 */
class Institution extends Model
{
    /** @use HasFactory<InstitutionFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'slug',
        'mode',
        'name',
        'klassci_api_url',
        'klassci_api_token_encrypted',
        'logo_url',
        'primary_color',
        'is_active',
        'settings',
    ];

    /**
     * Le defaut SQL ne remonte pas dans l'objet en memoire : sans cela,
     * `$institution->mode` vaut `null` juste apres la creation, alors que la
     * base porte bien `klassci`. Le declarer ici rend l'intention vraie des
     * l'instanciation, avant meme l'insertion.
     */
    protected $attributes = [
        'mode' => 'klassci',
    ];

    protected $casts = [
        'mode' => InstitutionMode::class,
        'is_active' => 'boolean',
        'settings' => 'array',
        'klassci_api_token_encrypted' => 'encrypted',
    ];

    /**
     * Jamais sérialisé en réponse API. Le token KLASSCI (cast `encrypted`,
     * donc déchiffré à l'accès) fuyait en clair quand le modèle complet était
     * renvoyé (InstitutionController store/update/toggle, route `role:supradmin`).
     * L'accès interne reste intact via l'accessor `klassci_api_token`.
     */
    protected $hidden = [
        'klassci_api_token_encrypted',
    ];

    /**
     * Accessor: transparent access to encrypted API token via old attribute name
     * Allows KlassciProxyService to continue using $institution->klassci_api_token without changes
     */
    public function getKlassciApiTokenAttribute(): ?string
    {
        return $this->klassci_api_token_encrypted;
    }

    /**
     * Mutator: transparent write to encrypted API token via old attribute name
     */
    public function setKlassciApiTokenAttribute(?string $value): void
    {
        $this->klassci_api_token_encrypted = $value;
    }

    /**
     * Trouver une institution par son slug
     */
    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->where('is_active', true)->first();
    }

    /**
     * Retourne la config KLASSCI pour cette institution
     *
     * @return array{url: string|null, token: string|null}
     */
    public function getKlassciConfig(): array
    {
        return [
            'url' => $this->klassci_api_url,
            'token' => $this->klassci_api_token,
        ];
    }

    /**
     * Relation : tous les users de cette institution
     *
     * @return HasMany<User, $this>
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Classe, $this>
     */
    public function classes()
    {
        return $this->hasMany(Classe::class);
    }

    /**
     * @return HasMany<Lesson, $this>
     */
    public function lessons()
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * @return HasMany<Evaluation, $this>
     */
    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }
}
