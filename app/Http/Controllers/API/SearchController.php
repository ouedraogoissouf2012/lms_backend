<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Lesson;
use App\Models\Evaluation;

class SearchController extends AuthenticatedController
{
    /**
     * Recherche globale dans le système
     * Recherche dans: utilisateurs, cours (lessons), évaluations, classes, matières
     */
    public function globalSearch(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2|max:100'
        ]);

        $query = $request->input('query');
        $user = $this->authenticatedUser($request);
        $limit = $request->input('limit', 5); // Limite par catégorie

        $cacheKey = "global_search_" . md5($query . $user->id . $limit);
        $cacheTTL = 300; // 5 minutes

        $results = Cache::remember($cacheKey, $cacheTTL, function () use ($query, $user, $limit) {
            $results = [
                'users' => [],
                'lessons' => [],
                'evaluations' => [],
                'classes' => [],
                'matieres' => []
            ];

            // 1. Rechercher des utilisateurs (admin/coordinateur seulement)
            if ($user->isCoordinator() || $user->isAdmin()) {
                $results['users'] = User::where(function ($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('email', 'LIKE', "%{$query}%");
                })
                ->limit($limit)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'title' => $user->name,
                        'subtitle' => $user->email,
                        'description' => $user->role_display_name,
                        'type' => 'user',
                        'icon' => 'UserIcon',
                        'url' => '/admin/users/' . $user->id
                    ];
                });
            }

            // 2. Rechercher des leçons
            $results['lessons'] = Lesson::where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%")
                  ->orWhere('content', 'LIKE', "%{$query}%");
            })
            ->where(function ($q) use ($user) {
                // Les enseignants ne voient que leurs leçons
                if ($user->isTeacher()) {
                    $q->where('teacher_id', $user->id);
                }
                // Les étudiants voient les leçons publiées
                if ($user->isStudent()) {
                    $q->where('status', 'published');
                }
            })
            ->limit($limit)
            ->get()
            ->map(function ($lesson) {
                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'subtitle' => 'Leçon',
                    'description' => \Illuminate\Support\Str::limit($lesson->description, 100),
                    'type' => 'lesson',
                    'icon' => 'BookOpenIcon',
                    'url' => '/teacher/lessons/' . $lesson->id
                ];
            });

            // 3. Rechercher des évaluations
            $results['evaluations'] = Evaluation::where(function ($q) use ($query) {
                $q->where('title', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%");
            })
            ->where(function ($q) use ($user) {
                // Les enseignants ne voient que leurs évaluations
                if ($user->isTeacher()) {
                    $q->where('teacher_id', $user->id);
                }
                // Les étudiants voient les évaluations publiées
                if ($user->isStudent()) {
                    $q->where('status', 'published');
                }
            })
            ->limit($limit)
            ->get()
            ->map(function ($evaluation) {
                return [
                    'id' => $evaluation->id,
                    'title' => $evaluation->title,
                    'subtitle' => 'Évaluation',
                    'description' => \Illuminate\Support\Str::limit($evaluation->description, 100),
                    'type' => 'evaluation',
                    'icon' => 'DocumentTextIcon',
                    'url' => '/teacher/evaluations/' . $evaluation->id
                ];
            });

            // 4. Rechercher dans les classes KLASSCI (via proxy)
            if ($user->isCoordinator() || $user->isAdmin() || $user->isTeacher()) {
                try {
                    $klassciService = app(\App\Services\KlassciProxyService::class);
                    $allClasses = $klassciService->getClasses();

                    $filteredClasses = collect($allClasses)->filter(function ($classe) use ($query) {
                        return stripos($classe['name'] ?? '', $query) !== false ||
                               stripos($classe['filiere']['name'] ?? '', $query) !== false ||
                               stripos($classe['niveau']['name'] ?? '', $query) !== false;
                    })->take($limit);

                    $results['classes'] = $filteredClasses->map(function ($classe) {
                        return [
                            'id' => $classe['id'],
                            'title' => $classe['name'],
                            'subtitle' => 'Classe',
                            'description' => ($classe['filiere']['name'] ?? '') . ' - ' . ($classe['niveau']['name'] ?? ''),
                            'type' => 'classe',
                            'icon' => 'BuildingLibraryIcon',
                            'url' => '/admin/classes/' . $classe['id']
                        ];
                    })->values();
                } catch (\Exception $e) {
                    \Log::error('Erreur recherche classes KLASSCI:', ['error' => $e->getMessage()]);
                }
            }

            // 5. Rechercher dans les matières KLASSCI
            if ($user->isCoordinator() || $user->isAdmin() || $user->isTeacher()) {
                try {
                    $klassciService = app(\App\Services\KlassciProxyService::class);
                    $allMatieres = $klassciService->getMatieres();

                    $filteredMatieres = collect($allMatieres)->filter(function ($matiere) use ($query) {
                        return stripos($matiere['nom'] ?? '', $query) !== false ||
                               stripos($matiere['code'] ?? '', $query) !== false;
                    })->take($limit);

                    $results['matieres'] = $filteredMatieres->map(function ($matiere) {
                        return [
                            'id' => $matiere['id'],
                            'title' => $matiere['nom'],
                            'subtitle' => 'Matière',
                            'description' => 'Code: ' . ($matiere['code'] ?? 'N/A'),
                            'type' => 'matiere',
                            'icon' => 'AcademicCapIcon',
                            'url' => '/admin/matieres/' . $matiere['id']
                        ];
                    })->values();
                } catch (\Exception $e) {
                    \Log::error('Erreur recherche matières KLASSCI:', ['error' => $e->getMessage()]);
                }
            }

            return $results;
        });

        // Calculer le total de résultats
        $total = collect($results)->flatten(1)->count();

        return response()->json([
            'success' => true,
            'query' => $query,
            'results' => $results,
            'total' => $total,
            'categories' => [
                'users' => count($results['users']),
                'lessons' => count($results['lessons']),
                'evaluations' => count($results['evaluations']),
                'classes' => count($results['classes']),
                'matieres' => count($results['matieres'])
            ]
        ]);
    }

    /**
     * Suggestions de recherche (autocomplete)
     */
    public function suggestions(Request $request)
    {
        $request->validate([
            'query' => 'required|string|min:2|max:50'
        ]);

        $query = $request->input('query');
        $user = $this->authenticatedUser($request);
        $limit = 10;

        $suggestions = [];

        // Suggestions de noms d'utilisateurs
        if ($user->isCoordinator() || $user->isAdmin()) {
            $userSuggestions = User::where('name', 'LIKE', "%{$query}%")
                ->limit($limit)
                ->pluck('name')
                ->toArray();
            $suggestions = array_merge($suggestions, $userSuggestions);
        }

        // Suggestions de titres de leçons
        $lessonSuggestions = Lesson::where('title', 'LIKE', "%{$query}%")
            ->where(function ($q) use ($user) {
                if ($user->isTeacher()) {
                    $q->where('teacher_id', $user->id);
                }
            })
            ->limit($limit)
            ->pluck('title')
            ->toArray();
        $suggestions = array_merge($suggestions, $lessonSuggestions);

        // Suggestions de titres d'évaluations
        $evaluationSuggestions = Evaluation::where('title', 'LIKE', "%{$query}%")
            ->where(function ($q) use ($user) {
                if ($user->isTeacher()) {
                    $q->where('teacher_id', $user->id);
                }
            })
            ->limit($limit)
            ->pluck('title')
            ->toArray();
        $suggestions = array_merge($suggestions, $evaluationSuggestions);

        // Garder seulement les suggestions uniques
        $suggestions = array_unique($suggestions);
        $suggestions = array_slice($suggestions, 0, $limit);

        return response()->json([
            'success' => true,
            'suggestions' => array_values($suggestions)
        ]);
    }

    /**
     * Historique de recherche de l'utilisateur
     */
    public function searchHistory(Request $request)
    {
        $user = $this->authenticatedUser($request);
        $cacheKey = "search_history_user_{$user->id}";

        $history = Cache::get($cacheKey, []);

        return response()->json([
            'success' => true,
            'history' => $history
        ]);
    }

    /**
     * Sauvegarder une recherche dans l'historique
     */
    public function saveSearchHistory(Request $request)
    {
        $request->validate([
            'query' => 'required|string|max:100'
        ]);

        $user = $this->authenticatedUser($request);
        $query = $request->input('query');
        $cacheKey = "search_history_user_{$user->id}";

        $history = Cache::get($cacheKey, []);

        // Ajouter la nouvelle recherche
        array_unshift($history, [
            'query' => $query,
            'timestamp' => now()->toIso8601String()
        ]);

        // Garder seulement les 10 dernières recherches
        $history = array_slice($history, 0, 10);

        // Sauvegarder pour 30 jours
        Cache::put($cacheKey, $history, 30 * 24 * 60 * 60);

        return response()->json([
            'success' => true,
            'message' => 'Recherche sauvegardée'
        ]);
    }
}
