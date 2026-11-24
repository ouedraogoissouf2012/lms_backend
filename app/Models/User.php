<?php

namespace App\Models;

use App\Casts\KlassciData;
use App\Models\Concerns\InteractsWithRoles;
use App\Models\Concerns\LienKlassciDuCompte;
use App\Models\Traits\BelongsToInstitution;
use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Utilisateur synchronisé depuis KLASSCI.
 *
 * @property int $id
 * @property string $role Rôle LMS — source d'autorité UNIQUE pour l'autorisation.
 * @property string|null $klassci_role Rôle KLASSCI du dernier sync — INFORMATIF, jamais pour
 *                                     autoriser (CRITICAL-05, `.claude/specs/critical-05-klassci-role-separation/`).
 * @property int|null $klassci_enseignant_id Write-once — autorité ownership enseignant (#119).
 *                                           Ne JAMAIS lire `klassci_data['enseignant_id']` pour autoriser.
 * @property array<string, mixed> $klassci_data Cache display filtré par whitelist (#477) via le cast
 *                                              {@see KlassciData} — toujours lu comme array.
 * @property Carbon|null $last_klassci_sync Cast `datetime` (déclaré dans la méthode casts()).
 * @property Carbon|null $deleted_at Soft delete (#566) — suppression LOGIQUE :
 *                                   dossier académique préservé, restauré à la re-sync KLASSCI.
 */
class User extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<UserFactory> */
    use BelongsToInstitution, CanResetPassword, HasApiTokens, HasFactory, InteractsWithRoles, LienKlassciDuCompte, Notifiable, SoftDeletes;

    protected $fillable = [
        'klassci_id', 'name', 'email', 'phone', 'password',
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

    /**
     * Relation: Classes où l'utilisateur est inscrit (pivot classe_etudiant).
     *
     * @return BelongsToMany<Classe, $this, ClasseEtudiant>
     */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classe::class, 'classe_etudiant')
            ->using(ClasseEtudiant::class)
            ->withPivot('date_inscription', 'statut', 'annee_universitaire_id')
            ->withTimestamps();
    }

    /**
     * Relation: Classes KLASSCI synchronisées (KlassciUserSynchronizer).
     *
     * @return HasMany<UserClass, $this>
     */
    public function klassciClasses(): HasMany
    {
        return $this->hasMany(UserClass::class);
    }

    /**
     * Relation: Posts de forum créés.
     *
     * @return HasMany<ForumPost, $this>
     */
    public function forumPosts(): HasMany
    {
        return $this->hasMany(ForumPost::class);
    }

    /**
     * Relation: Notifications applicatives (table custom `notifications`).
     * ⚠️ Override volontaire du trait Notifiable — sans lui, `$user->notifications`
     * pointerait sur les DatabaseNotification Laravel (schéma incompatible).
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Relation: Tentatives de quiz.
     *
     * @return HasMany<QuizAttempt, $this>
     */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Relation: Soumissions d'évaluation (FK `student_id`) — copies + notes (#566).
     *
     * @return HasMany<EvaluationSubmission, $this>
     */
    public function evaluationSubmissions(): HasMany
    {
        return $this->hasMany(EvaluationSubmission::class, 'student_id');
    }
}
