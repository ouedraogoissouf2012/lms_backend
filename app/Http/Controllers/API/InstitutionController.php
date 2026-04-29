<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesApiExceptions;
use App\Models\Institution;
use App\Models\User;
use App\Models\Lesson;
use App\Models\Evaluation;
use App\Models\Classe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class InstitutionController extends Controller
{
    use HandlesApiExceptions;
    /**
     * Liste toutes les institutions avec stats par institution + stats globales.
     * Bypass du Global Scope pour voir les données cross-tenant.
     */
    public function index()
    {
        $data = Cache::remember('global_institutions_list', 120, function () {
            $institutions = Institution::orderBy('name')->get();

            return $institutions->map(function ($institution) {
                $id = $institution->id;

                $usersCount = User::withoutGlobalScope('institution')
                    ->where('institution_id', $id)->count();

                $lessonsCount = Lesson::withoutGlobalScope('institution')
                    ->where('institution_id', $id)->count();

                $evaluationsCount = Evaluation::withoutGlobalScope('institution')
                    ->where('institution_id', $id)->count();

                $classesCount = Classe::withoutGlobalScope('institution')
                    ->where('institution_id', $id)->count();

                $lastActivity = User::withoutGlobalScope('institution')
                    ->where('institution_id', $id)->max('updated_at');

                return [
                    'id' => $institution->id,
                    'slug' => $institution->slug,
                    'name' => $institution->name,
                    'logo_url' => $institution->logo_url,
                    'primary_color' => $institution->primary_color,
                    'is_active' => $institution->is_active,
                    'klassci_api_url' => $institution->klassci_api_url,
                    'created_at' => $institution->created_at,
                    'updated_at' => $institution->updated_at,
                    'stats' => [
                        'users_count' => $usersCount,
                        'lessons_count' => $lessonsCount,
                        'evaluations_count' => $evaluationsCount,
                        'classes_count' => $classesCount,
                        'last_activity' => $lastActivity,
                    ],
                ];
            });
        });

        $globalStats = Cache::remember('global_institutions_overview', 120, function () {
            $total = Institution::count();
            $active = Institution::where('is_active', true)->count();

            return [
                'total_institutions' => $total,
                'active_institutions' => $active,
                'inactive_institutions' => $total - $active,
                'total_users' => User::withoutGlobalScope('institution')->count(),
                'total_lessons' => Lesson::withoutGlobalScope('institution')->count(),
                'total_evaluations' => Evaluation::withoutGlobalScope('institution')->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'institutions' => $data,
                'overview' => $globalStats,
            ],
        ]);
    }

    /**
     * Détails d'une institution avec stats détaillées.
     */
    public function show(int $id)
    {
        $institution = Institution::findOrFail($id);

        $usersCount = User::withoutGlobalScope('institution')
            ->where('institution_id', $id)->count();

        $usersByRole = User::withoutGlobalScope('institution')
            ->where('institution_id', $id)
            ->selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role');

        $lessonsCount = Lesson::withoutGlobalScope('institution')
            ->where('institution_id', $id)->count();

        $publishedLessons = Lesson::withoutGlobalScope('institution')
            ->where('institution_id', $id)
            ->where('status', 'published')->count();

        $evaluationsCount = Evaluation::withoutGlobalScope('institution')
            ->where('institution_id', $id)->count();

        $classesCount = Classe::withoutGlobalScope('institution')
            ->where('institution_id', $id)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'institution' => $institution,
                'stats' => [
                    'users_count' => $usersCount,
                    'users_by_role' => $usersByRole,
                    'lessons_count' => $lessonsCount,
                    'published_lessons' => $publishedLessons,
                    'evaluations_count' => $evaluationsCount,
                    'classes_count' => $classesCount,
                ],
            ],
        ]);
    }

    /**
     * Créer une nouvelle institution.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/', 'unique:institutions,slug'],
            'name' => 'required|string|max:191',
            'klassci_api_url' => 'required|url|max:500',
            'klassci_api_token' => 'nullable|string',
            'logo_url' => 'nullable|string|max:500',
            'primary_color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        $institution = Institution::create($validated);

        Cache::forget('global_institutions_list');
        Cache::forget('global_institutions_overview');

        return response()->json([
            'success' => true,
            'message' => 'Institution créée avec succès',
            'data' => $institution,
        ], 201);
    }

    /**
     * Modifier une institution existante.
     */
    public function update(Request $request, int $id)
    {
        $institution = Institution::findOrFail($id);

        $validated = $request->validate([
            'slug' => ['sometimes', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/', Rule::unique('institutions', 'slug')->ignore($id)],
            'name' => 'sometimes|string|max:191',
            'klassci_api_url' => 'sometimes|url|max:500',
            'klassci_api_token' => 'nullable|string',
            'logo_url' => 'nullable|string|max:500',
            'primary_color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        $institution->update($validated);

        Cache::forget('global_institutions_list');
        Cache::forget('global_institutions_overview');

        return response()->json([
            'success' => true,
            'message' => 'Institution mise à jour avec succès',
            'data' => $institution->fresh(),
        ]);
    }

    /**
     * Activer/Désactiver une institution.
     * Interdit de désactiver la dernière institution active.
     */
    public function toggle(int $id)
    {
        $institution = Institution::findOrFail($id);

        if ($institution->is_active) {
            $activeCount = Institution::where('is_active', true)->count();
            if ($activeCount <= 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de désactiver la dernière institution active',
                ], 422);
            }
        }

        $institution->update(['is_active' => !$institution->is_active]);

        Cache::forget('global_institutions_list');
        Cache::forget('global_institutions_overview');

        return response()->json([
            'success' => true,
            'message' => $institution->is_active
                ? 'Institution activée'
                : 'Institution désactivée',
            'data' => $institution->fresh(),
        ]);
    }

    /**
     * Supprimer une institution.
     */
    public function destroy(int $id)
    {
        $institution = Institution::findOrFail($id);

        $institution->delete();

        Cache::forget('global_institutions_list');
        Cache::forget('global_institutions_overview');

        return response()->json([
            'success' => true,
            'message' => 'Institution supprimée avec succès',
        ]);
    }

    /**
     * Tester la connexion KLASSCI d'une institution.
     */
    public function testConnection(int $id)
    {
        $institution = Institution::findOrFail($id);
        $config = $institution->getKlassciConfig();

        if (!$config['url']) {
            return response()->json([
                'success' => false,
                'message' => 'URL KLASSCI non configurée pour cette institution',
            ], 422);
        }

        $startTime = microtime(true);

        $testUrl = rtrim($config['url'], '/') . '/auth/check-user';

        $http = Http::timeout(10)->withHeaders(['Accept' => 'application/json']);
        if (!config('services.klassci.ssl_verify', true)) {
            $http = $http->withoutVerifying();
        }
        $response = $http->post($testUrl, ['identifier' => 'lms-ping-test']);

        $responseTime = round((microtime(true) - $startTime) * 1000);

        if ($response->status() === 200 || $response->status() === 422 || $response->status() === 404) {
            return response()->json([
                'success' => true,
                'message' => 'Connexion KLASSCI réussie',
                'data' => [
                    'status_code' => $response->status(),
                    'response_time_ms' => $responseTime,
                    'api_url' => $config['url'],
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Le serveur KLASSCI a répondu avec une erreur (code ' . $response->status() . ')',
            'data' => [
                'status_code' => $response->status(),
                'api_url' => $config['url'],
                'response_time_ms' => $responseTime,
            ],
        ], 502);
    }
}
