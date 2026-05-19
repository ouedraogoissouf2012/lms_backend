<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model User - Synchronisé avec KLASSCI
 *
 * Ce modèle représente les utilisateurs synchronisés depuis l'API KLASSCI
 *
 * @property int $id
 * @property string $role             Rôle LMS — source de vérité unique pour TOUTES les
 *                                    décisions d'autorisation (middlewares, policies, controllers).
 * @property string|null $klassci_role Rôle reçu de KLASSCI lors du dernier sync.
 *                                    PURELY INFORMATIONAL — ne JAMAIS utiliser pour autorisation.
 *                                    Voir `.claude/specs/critical-05-klassci-role-separation/`.
 * @property int|null    $klassci_enseignant_id  ID enseignant dans KLASSCI.
 *                                    Initialisé au sign-up KLASSCI ; jamais réécrit par re-sync.
 *                                    Source d'autorité unique pour les checks d'ownership enseignant
 *                                    (DeleteEvaluationRequest, PublishEvaluationRequest, UpdateEvaluationRequest).
 *                                    Ne JAMAIS lire `klassci_data['enseignant_id']` pour l'autorisation.
 *                                    Voir `.claude/specs/klassci-enseignant-id-separation/`.
 * @property int|null $institution_id
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, BelongsToInstitution;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'klassci_id',
        'name',
        'email',
        'password',
        'role',
        'klassci_role',
        'klassci_enseignant_id',
        'klassci_token_encrypted',
        'klassci_tenant_url',
        'klassci_data',
        'last_klassci_sync',
        'institution_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'klassci_token_encrypted',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_klassci_sync' => 'datetime',
            'klassci_token_encrypted' => 'encrypted',
        ];
    }

    /**
     * Get klassci_data as decoded array. Handles both JSON strings and native JSON columns.
     * Production-grade: explicitly decodes JSON since database stores as TEXT/JSON
     *
     * ⚠️ SÉCURITÉ — ce blob est un cache display informationnel. Il est écrasé EN BLOC à
     * chaque re-sync KLASSCI 24h (`EnsureKlassciSync`), donc une instance KLASSCI compromise
     * peut y pousser n'importe quoi.
     *
     * NE JAMAIS lire `$user->klassci_data['XXX_id']` pour de l'AUTORISATION. Les champs
     * d'autorité ont leur colonne dédiée write-once :
     *   • `role`                  (LMS — source d'autorité applicative)
     *   • `klassci_role`          (info, NE PAS lire pour autoriser — voir CRITICAL-05)
     *   • `klassci_enseignant_id` (ownership évaluations — voir #119)
     *
     * Lire le blob pour l'affichage / les rapports / les champs informatifs reste OK.
     */
    public function getKlassciDataAttribute($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            return json_decode($value, true) ?: [];
        }
        return [];
    }

    /**
     * Set klassci_data: accepts array or JSON string, stores as JSON-encoded string
     */
    public function setKlassciDataAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['klassci_data'] = json_encode($value);
        } else {
            $this->attributes['klassci_data'] = $value;
        }
    }

    /**
     * Accessor: transparent access to encrypted token via old attribute name
     * Allows KlassciProxyService to continue using $user->klassci_token without changes
     */
    public function getKlassciTokenAttribute(): ?string
    {
        return $this->klassci_token_encrypted;
    }

    /**
     * Mutator: transparent write to encrypted token via old attribute name
     */
    public function setKlassciTokenAttribute(?string $value): void
    {
        $this->klassci_token_encrypted = $value;
    }

    /**
     * Vérifie si l'utilisateur est un enseignant
     */
    public function isTeacher(): bool
    {
        return $this->role === 'enseignant' || $this->role === 'teacher';
    }

    /**
     * Vérifie si l'utilisateur est un coordinateur
     */
    public function isCoordinator(): bool
    {
        return $this->role === 'coordinateur' || $this->role === 'coordinator';
    }

    /**
     * Vérifie si l'utilisateur est un étudiant
     */
    public function isStudent(): bool
    {
        return $this->role === 'etudiant' || $this->role === 'student';
    }

    /**
     * Vérifie si l'utilisateur est un admin
     */
    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'administrateur', 'superAdmin', 'supradmin']);
    }

    /**
     * Vérifie si les données KLASSCI sont à jour (< 24h)
     */
    public function isKlassciDataFresh(): bool
    {
        if (!$this->last_klassci_sync) {
            return false;
        }

        return $this->last_klassci_sync->isAfter(now()->subDay());
    }

    /**
     * Relation: Progression des cours
     */
    public function lessonProgress()
    {
        return $this->hasMany(\App\Models\LessonProgress::class);
    }

    /**
     * Relation: Cours complétés
     */
    public function completedLessons()
    {
        return $this->belongsToMany(\App\Models\Lesson::class, 'lesson_progress')
            ->wherePivot('status', 'completed');
    }

    /**
     * Relation: Classes où l'utilisateur est inscrit (étudiant)
     */
    public function classes()
    {
        return $this->belongsToMany(\App\Models\Classe::class, 'classe_etudiant')
            ->withPivot('date_inscription', 'statut', 'annee_universitaire_id')
            ->withTimestamps();
    }

    /**
     * Relation: Classes KLASSCI de l'utilisateur
     */
    public function klassciClasses()
    {
        return $this->hasMany(\App\Models\UserClass::class);
    }

    /**
     * Relation: Classes actives uniquement
     */
    public function classesActives()
    {
        return $this->classes()->wherePivot('statut', 'actif');
    }

    /**
     * Relation: Cours créés (enseignant)
     */
    public function lessonsCreated()
    {
        return $this->hasMany(\App\Models\Lesson::class, 'enseignant_id');
    }

    /**
     * Relation: Topics de forum créés
     */
    public function forumTopics()
    {
        return $this->hasMany(\App\Models\ForumTopic::class);
    }

    /**
     * Relation: Posts de forum créés
     */
    public function forumPosts()
    {
        return $this->hasMany(\App\Models\ForumPost::class);
    }

    /**
     * Relation: Fichiers uploadés
     */
    public function files()
    {
        return $this->hasMany(\App\Models\File::class);
    }

    /**
     * Relation: Notifications de l'utilisateur
     */
    public function notifications()
    {
        return $this->hasMany(\App\Models\Notification::class);
    }

    /**
     * Relation: Notifications non lues
     */
    public function unreadNotifications()
    {
        return $this->notifications()->unread();
    }

    /**
     * Relation: Notifications lues
     */
    public function readNotifications()
    {
        return $this->notifications()->read();
    }

    /**
     * Relation: Tentatives de quiz
     */
    public function quizAttempts()
    {
        return $this->hasMany(\App\Models\QuizAttempt::class);
    }
}
