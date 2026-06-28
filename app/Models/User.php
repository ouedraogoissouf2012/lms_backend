<?php

namespace App\Models;

use App\Casts\KlassciData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Concerns\InteractsWithRoles;
use App\Models\Traits\BelongsToInstitution;

/**
 * Utilisateur synchronisé depuis KLASSCI.
 *
 * @property int $id
 * @property string $role             Rôle LMS — source d'autorité UNIQUE pour l'autorisation.
 * @property string|null $klassci_role Rôle KLASSCI du dernier sync — INFORMATIF, jamais pour
 *                                    autoriser (CRITICAL-05, `.claude/specs/critical-05-klassci-role-separation/`).
 * @property int|null $klassci_enseignant_id Write-once — autorité ownership enseignant (#119).
 *                                    Ne JAMAIS lire `klassci_data['enseignant_id']` pour autoriser.
 * @property int|null $institution_id
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, BelongsToInstitution, InteractsWithRoles;

    protected $fillable = [
        'klassci_id', 'name', 'email', 'password',
        'role', 'klassci_role', 'klassci_enseignant_id',
        // 'klassci_token' = alias mass-assignable du mutateur setKlassciTokenAttribute
        // (→ colonne chiffrée klassci_token_encrypted). Sans lui, le sync droppait
        // silencieusement le token au login → 401 "Token KLASSCI non trouvé".
        'klassci_token', 'klassci_token_encrypted', 'klassci_tenant_url', 'klassci_data',
        'last_klassci_sync', 'institution_id',
    ];

    protected $hidden = [
        'password', 'remember_token', 'klassci_token_encrypted',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_klassci_sync' => 'datetime',
            'klassci_token_encrypted' => 'encrypted',
            'klassci_data' => KlassciData::class,
        ];
    }

    /** Accessor alias : lecture du token chiffré via l'ancien nom d'attribut. */
    public function getKlassciTokenAttribute(): ?string
    {
        return $this->klassci_token_encrypted;
    }

    /** Mutator alias : écriture du token chiffré via l'ancien nom d'attribut. */
    public function setKlassciTokenAttribute(?string $value): void
    {
        $this->klassci_token_encrypted = $value;
    }

    /** Les données KLASSCI sont-elles fraîches (< 24h) ? Utilisé par EnsureKlassciSync. */
    public function isKlassciDataFresh(): bool
    {
        if (!$this->last_klassci_sync) {
            return false;
        }

        return $this->last_klassci_sync->isAfter(now()->subDay());
    }

    /** Relation: Classes où l'utilisateur est inscrit (pivot classe_etudiant). */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Classe::class, 'classe_etudiant')
            ->withPivot('date_inscription', 'statut', 'annee_universitaire_id')
            ->withTimestamps();
    }

    /** Relation: Classes KLASSCI synchronisées (KlassciUserSynchronizer). */
    public function klassciClasses(): HasMany
    {
        return $this->hasMany(\App\Models\UserClass::class);
    }

    /** Relation: Posts de forum créés. */
    public function forumPosts(): HasMany
    {
        return $this->hasMany(\App\Models\ForumPost::class);
    }

    /**
     * Relation: Notifications applicatives (table custom `notifications`).
     * ⚠️ Override volontaire du trait Notifiable — sans lui, `$user->notifications`
     * pointerait sur les DatabaseNotification Laravel (schéma incompatible).
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(\App\Models\Notification::class);
    }

    /** Relation: Tentatives de quiz. */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(\App\Models\QuizAttempt::class);
    }
}
