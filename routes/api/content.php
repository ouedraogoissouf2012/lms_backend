<?php

use Illuminate\Support\Facades\Route;

// ============================================
// CHAPTERS (Chapitres de leçons) - Routes protégées
// NOUVELLE STRUCTURE: Chapter belongsTo Lesson
// ============================================
use App\Http\Controllers\API\ChapterController;
use App\Http\Controllers\API\ChapterOriginalController;
use App\Http\Controllers\API\ChapterSlideController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste chapitres d'une leçon
    Route::get('lessons/{lessonId}/chapters', [ChapterController::class, 'index']);
    // Détails d'un chapitre
    Route::get('chapters/{id}', [ChapterController::class, 'show']);
    // #598 — document source : SEUL chemin d'accès depuis qu'il vit sur le
    // disque privé (avant, une URL /storage/... le servait sans authentification).
    Route::get('chapters/{chapter}/original', [ChapterOriginalController::class, 'show'])
        ->middleware('throttle:30,1');

    // Progression des chapitres
    Route::get('lessons/{lessonId}/chapter-progress', [\App\Http\Controllers\API\ChapterProgressController::class, 'getLessonProgress']);
    Route::get('chapters/{chapterId}/progress', [\App\Http\Controllers\API\ChapterProgressController::class, 'getChapterProgress']);
    Route::post('chapters/{chapterId}/complete', [\App\Http\Controllers\API\ChapterProgressController::class, 'markAsCompleted']);
    Route::post('chapters/{chapterId}/time', [\App\Http\Controllers\API\ChapterProgressController::class, 'updateTimeSpent']);
});

// #620 — PNG signées : pas de Bearer (balise <img>). La signature est le jeton.
Route::get('chapters/{chapter}/slides/{slide}', [ChapterSlideController::class, 'show'])
    ->middleware(['signed', 'throttle:120,1'])
    ->whereNumber('chapter')
    ->whereNumber('slide')
    ->name('chapters.slides.show');

// Routes enseignants/coordinateurs/admins
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->group(function () {
    // CRUD des chapitres
    Route::post('lessons/{lessonId}/chapters', [ChapterController::class, 'store'])
        ->middleware('throttle:60,1');
    Route::match(['put', 'patch'], 'chapters/{id}', [ChapterController::class, 'update'])
        ->middleware('throttle:30,1');
    Route::delete('chapters/{id}', [ChapterController::class, 'destroy'])
        ->middleware('throttle:30,1');

    // Sortie de corbeille (#689) : meme garde que la suppression, la cible
    // etant simplement cherchee withTrashed().
    Route::post('chapters/{id}/restore', [ChapterController::class, 'restore'])
        ->middleware('throttle:30,1');

    // Upload fichier PowerPoint/Word/PDF (max 30 MB) - Rate limited: 60/min
    Route::post('chapters/{chapterId}/upload', [ChapterController::class, 'uploadFile'])
        ->middleware('throttle:60,1');
    Route::get('chapters/uploads/{id}/status', [ChapterController::class, 'uploadStatus'])
        ->name('chapters.uploads.status');

    // Réorganisation (drag & drop) - Rate limited: 100/min (frequent user action)
    Route::post('lessons/{lessonId}/chapters/reorder', [ChapterController::class, 'reorder'])
        ->middleware('throttle:100,1');
});

// ============================================
// KNOWLEDGE CHECKS (Quiz "Testez vos connaissances")
// Split SRP : CRUD + Attempts dans 2 controllers thin
// ============================================
use App\Http\Controllers\API\KnowledgeCheckCrudController;
use App\Http\Controllers\API\KnowledgeCheckAttemptController;

Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste des quiz d'un chapitre
    Route::get('knowledge-checks', [KnowledgeCheckCrudController::class, 'index']);
    // Quiz par chapitre (doit être AVANT {id} pour éviter le conflit)
    Route::get('knowledge-checks/chapter/{chapterId}', [KnowledgeCheckCrudController::class, 'getByChapter']);
    Route::get('knowledge-checks/{id}', [KnowledgeCheckCrudController::class, 'show']);

    // Tentatives (étudiants)
    Route::post('knowledge-checks/{id}/start', [KnowledgeCheckAttemptController::class, 'startAttempt'])
        ->middleware('throttle:300,1');
    Route::post('knowledge-checks/{id}/submit', [KnowledgeCheckAttemptController::class, 'submitAttempt'])
        ->middleware('throttle:60,1');
    Route::get('knowledge-checks/{id}/my-attempts', [KnowledgeCheckAttemptController::class, 'myAttempts']);

    // CRUD (enseignants/admins)
    Route::post('knowledge-checks', [KnowledgeCheckCrudController::class, 'store']);
    Route::put('knowledge-checks/{id}', [KnowledgeCheckCrudController::class, 'update']);
    Route::delete('knowledge-checks/{id}', [KnowledgeCheckCrudController::class, 'destroy']);
});

// ============================================
// LESSONS (Cours/Leçons) - Routes protégées
// ============================================
use App\Http\Controllers\API\Lesson\LessonCrudController;
use App\Http\Controllers\API\Lesson\LessonProgressController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des cours
    Route::get('lessons', [LessonCrudController::class, 'index']);
    Route::get('lessons/my-courses', [LessonCrudController::class, 'myCourses']); // Cours de l'étudiant avec filtres
    Route::get('lessons/{id}', [LessonCrudController::class, 'show']);

    // Progression (Tous peuvent voir leur progression)
    Route::get('lessons/{id}/progress', [LessonProgressController::class, 'getProgress']);
    Route::post('lessons/{id}/progress', [LessonProgressController::class, 'updateProgress'])
        ->middleware('throttle:300,1');
    Route::post('lessons/{id}/complete', [LessonProgressController::class, 'markComplete'])
        ->middleware('throttle:300,1');
    Route::post('lessons/{id}/rating', [LessonProgressController::class, 'rate'])
        ->middleware('throttle:300,1');
});

// Routes enseignants/coordinateurs/admins
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->group(function () {
    // CRUD des cours
    Route::post('lessons', [LessonCrudController::class, 'store'])
        ->middleware('throttle:60,1');
    Route::match(['put', 'patch'], 'lessons/{id}', [LessonCrudController::class, 'update'])
        ->middleware('throttle:30,1');
    Route::delete('lessons/{id}', [LessonCrudController::class, 'destroy'])
        ->middleware('throttle:30,1');

    // Actions spéciales
    Route::post('lessons/{id}/publish', [LessonCrudController::class, 'publish'])
        ->middleware('throttle:100,1');
    Route::post('lessons/{id}/unpublish', [LessonCrudController::class, 'unpublish'])
        ->middleware('throttle:100,1');
});

// ============================================
// FORUM - Routes protégées
// ============================================
use App\Http\Controllers\API\ForumController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->prefix('forum')->group(function () {
    // Topics
    Route::get('topics', [ForumController::class, 'index']);
    Route::post('topics', [ForumController::class, 'store'])
        ->middleware('throttle:100,1');
    Route::get('topics/{topic}', [ForumController::class, 'show']);
    Route::put('topics/{topic}', [ForumController::class, 'update'])
        ->middleware('throttle:100,1');
    Route::delete('topics/{topic}', [ForumController::class, 'destroy'])
        ->middleware('throttle:30,1');

    // Posts
    Route::post('topics/{topic}/posts', [ForumController::class, 'storePost'])
        ->middleware('throttle:100,1');
    Route::put('posts/{post}', [ForumController::class, 'updatePost'])
        ->middleware('throttle:100,1');
    Route::delete('posts/{post}', [ForumController::class, 'destroyPost'])
        ->middleware('throttle:30,1');

    // Marquer solution
    Route::post('posts/{post}/solution', [ForumController::class, 'markAsSolution'])
        ->middleware('throttle:100,1');
});

// Routes pour clôturer et épingler des topics (formateurs/coordinateurs/admins)
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->prefix('forum')->group(function () {
    Route::post('topics/{topic}/close', [ForumController::class, 'closeTopic']);
    Route::post('topics/{topic}/pin', [ForumController::class, 'pinTopic']);
});

// ============================================
// FILES - Routes protégées
// ============================================
use App\Http\Controllers\API\FileController;

// Routes accessibles à tous les utilisateurs authentifiés.
// IMPORTANT: les routes statiques (`files/upload`, `files/stats`) DOIVENT être
// déclarées AVANT `files/{id}` — sinon Laravel matche `{id}=upload|stats`
// dans l'ordre et la route statique devient inaccessible (404).
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste
    Route::get('files', [FileController::class, 'index']);

    // Upload de fichiers (Tous peuvent uploader) - Rate limited: 60/min (with size limit)
    Route::post('files/upload', [FileController::class, 'upload'])
        ->middleware('throttle:60,1');

    // Statistiques (déclarée avant `files/{id}` pour éviter le matching de `{id}=stats`)
    Route::get('files/stats', [FileController::class, 'stats']);

    // Consultation par id (après les routes statiques)
    Route::get('files/{id}', [FileController::class, 'show']);
    Route::get('files/{id}/download', [FileController::class, 'download'])->name('api.files.download');

    // Mise à jour et suppression (propriétaire ou admin)
    Route::put('files/{file}', [FileController::class, 'update'])
        ->middleware('throttle:60,1');
    Route::delete('files/{file}', [FileController::class, 'destroy'])
        ->middleware('throttle:30,1');
});

// ============================================
// QUIZZES - Routes protégées
// ============================================
use App\Http\Controllers\API\Quiz\QuizAttemptStudentController;
use App\Http\Controllers\API\Quiz\QuizAttemptTeacherController;
use App\Http\Controllers\API\Quiz\QuizCrudController;

// Routes accessibles à tous les utilisateurs authentifiés
Route::middleware(['auth:sanctum', 'klassci.sync'])->group(function () {
    // Liste et consultation des quiz
    Route::get('quizzes', [QuizCrudController::class, 'index']);
    Route::get('quizzes/{quiz}', [QuizCrudController::class, 'show']);

    // Démarrer et soumettre une tentative
    Route::post('quizzes/{quiz}/start', [QuizAttemptStudentController::class, 'startAttempt'])
        ->middleware('throttle:300,1');
    Route::post('quiz-attempts/{id}/submit', [QuizAttemptStudentController::class, 'submitAttempt'])
        ->middleware('throttle:60,1');

    // NOUVEAU: Timer et sauvegarde de progression
    Route::get('quiz-attempts/{id}/time-remaining', [QuizAttemptStudentController::class, 'checkTimeRemaining']);
    Route::post('quiz-attempts/{id}/save-progress', [QuizAttemptStudentController::class, 'saveProgress']);

    // Consulter une tentative
    Route::get('quiz-attempts/{id}', [QuizAttemptStudentController::class, 'showAttempt']);
});

// Routes enseignants/coordinateurs/admins
Route::middleware(['auth:sanctum', 'klassci.sync', 'role:enseignant,coordinateur,admin'])->group(function () {
    // CRUD des quiz
    Route::post('quizzes', [QuizCrudController::class, 'store']);
    Route::put('quizzes/{quiz}', [QuizCrudController::class, 'update']);
    Route::delete('quizzes/{quiz}', [QuizCrudController::class, 'destroy']);

    // Publication
    Route::post('quizzes/{quiz}/publish', [QuizCrudController::class, 'publish']);

    // Gestion des tentatives
    Route::get('quizzes/{quiz}/attempts', [QuizAttemptTeacherController::class, 'getAttempts']);
    Route::post('quiz-attempts/{id}/grade', [QuizAttemptTeacherController::class, 'gradeAttempt']);
});
