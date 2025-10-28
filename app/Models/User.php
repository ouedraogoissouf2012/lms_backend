<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Model User - Synchronisé avec KLASSCI
 *
 * Ce modèle représente les utilisateurs synchronisés depuis l'API KLASSCI
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

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
        'klassci_token',
        'klassci_data',
        'last_klassci_sync',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'klassci_token',
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
        ];
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
        return $this->role === 'admin' || $this->role === 'administrateur';
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
