<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'klassci_api_url',
        'klassci_api_token',
        'logo_url',
        'primary_color',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

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
