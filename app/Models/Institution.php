<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'klassci_api_url',
        'klassci_api_token_encrypted',
        'logo_url',
        'primary_color',
        'is_active',
        'settings',
    ];

    protected $casts = [
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
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function classes()
    {
        return $this->hasMany(Classe::class);
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class);
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }
}
