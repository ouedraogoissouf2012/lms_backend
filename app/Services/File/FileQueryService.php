<?php

declare(strict_types=1);

namespace App\Services\File;

use App\Enums\Role;
use App\Models\File;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * FileQueryService — extrait verbatim de `FileController` (split-16/file).
 *
 * Responsabilité unique : lectures sur `App\Models\File` (listing avec filtres,
 * récupération unitaire avec contrôle d'accès, agrégations statistiques cachées).
 *
 * ## DI strict (§1.6 D)
 *
 * Le `Repository` de cache est injecté via le contrat Laravel — aucun appel à
 * Facade ou `app()`. Le tenant scope (`institution_id`) et l'auteur sont passés
 * en argument (cf. `BelongsToInstitution` global scope) ; le service ne déduit
 * jamais l'utilisateur courant en silence.
 *
 * ## Stats — cache par institution + rôle
 *
 * Conserve la clé "files_stats_inst_{id}_user_{id}" / "files_stats_inst_{id}"
 * / "files_stats_global" pour préserver l'isolation cross-tenant (issue #102,
 * cf. NotificationsController::stats §98).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (Repository, pas Facade)
 * @see app/Http/Controllers/API/FileController.php (caller thin)
 */
final class FileQueryService
{
    /**
     * TTL du cache des statistiques fichiers (5 minutes) — aligne avec
     * NotificationsController::stats (issue #98).
     */
    private const STATS_CACHE_TTL = 300;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly FilePresenter $presenter,
    ) {}

    /**
     * Liste paginée filtrée — appelée par `GET /api/files`.
     *
     * Les étudiants sont restreints à `is_public = true OR user_id = $caller->id`.
     * Les filtres `type|category|user_id|fileable_*|sort|per_page` proviennent de
     * `ListFilesRequest::validated()` (déjà whitelistés, cf. issue #10 IDOR).
     *
     * @param  array<string, mixed>  $filters  Validated input from ListFilesRequest.
     * @return LengthAwarePaginator<int, File>
     */
    public function list(User $caller, array $filters): LengthAwarePaginator
    {
        $query = File::with(['user:id,name,email,role']);

        if ($caller->isStudent()) {
            $query->where(function ($q) use ($caller) {
                $q->where('is_public', true)
                  ->orWhere('user_id', $caller->id);
            });
        }

        if (isset($filters['type'])) {
            $query->ofType($filters['type']);
        }

        if (isset($filters['category'])) {
            $query->ofCategory($filters['category']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['fileable_type'], $filters['fileable_id'])) {
            $query->where('fileable_type', $filters['fileable_type'])
                  ->where('fileable_id', $filters['fileable_id']);
        }

        $sortBy = $filters['sort'] ?? 'recent';
        switch ($sortBy) {
            case 'name':
                $query->orderBy('original_name', 'asc');
                break;
            case 'size':
                $query->orderBy('size_bytes', 'desc');
                break;
            case 'downloads':
                $query->orderBy('downloads_count', 'desc');
                break;
            default:
                $query->orderBy('created_at', 'desc');
        }

        $perPage = $filters['per_page'] ?? 20;
        $files = $query->paginate($perPage);

        $files->getCollection()->transform(function (File $file): File {
            $file->formatted_size = $this->presenter->formattedSize($file);
            $file->download_url = $this->presenter->downloadUrl($file);
            return $file;
        });

        return $files;
    }

    /**
     * Récupération unitaire d'un fichier — appelée par `GET /api/files/{id}`.
     *
     * Renvoie `null` si l'ID est inconnu (le caller traduit en 404). Les attributs
     * dynamiques `formatted_size` / `download_url` sont attachés ici comme dans
     * la version pré-refacto.
     *
     * L'autorisation `canReadFile` reste dans le controller (trait
     * `ChecksFileAuthorization`) — pas dupliquée dans le service.
     */
    public function find(int $id): ?File
    {
        $file = File::with(['user:id,name,email,role', 'fileable'])->find($id);

        if ($file === null) {
            return null;
        }

        $file->formatted_size = $this->presenter->formattedSize($file);
        $file->download_url = $this->presenter->downloadUrl($file);

        return $file;
    }

    /**
     * Statistiques agrégées sur les fichiers — appelées par `GET /api/files/stats`.
     *
     * Tenant isolation (issue #102) :
     *   - supradmin → counts globaux cross-tenant
     *   - admin/teacher → counts scopés à `caller->institution_id`
     *   - student → counts scopés à `caller->institution_id` + `user_id = caller->id`
     *
     * Clé cache namespacée par institution + role pour éviter toute fuite
     * cross-tenant via `Cache::remember`.
     *
     * @return array<string, mixed>
     */
    public function stats(User $caller): array
    {
        $isSupradmin = $caller->asRoleEnum() === Role::Supradmin;
        $isStudent = $caller->isStudent();

        $cacheKey = $isSupradmin
            ? 'files_stats_global'
            : ($isStudent
                ? "files_stats_inst_{$caller->institution_id}_user_{$caller->id}"
                : "files_stats_inst_{$caller->institution_id}");

        $applyScope = function ($query) use ($caller, $isSupradmin, $isStudent) {
            if ($isSupradmin) {
                return $query;
            }
            $query->where('institution_id', $caller->institution_id);
            if ($isStudent) {
                $query->where('user_id', $caller->id);
            }
            return $query;
        };

        return $this->cache->remember($cacheKey, self::STATS_CACHE_TTL, function () use ($applyScope): array {
            $payload = [
                'total_files' => $applyScope(File::query())->count(),
                'total_size_bytes' => $applyScope(File::query())->sum('size_bytes'),
                'total_downloads' => $applyScope(File::query())->sum('downloads_count'),
                'by_type' => $applyScope(File::query())
                    ->selectRaw('type, COUNT(*) as count, SUM(size_bytes) as total_size')
                    ->groupBy('type')
                    ->get(),
                'by_category' => $applyScope(File::query())
                    ->selectRaw('category, COUNT(*) as count')
                    ->whereNotNull('category')
                    ->groupBy('category')
                    ->get(),
                'recent_uploads' => $applyScope(File::with('user:id,name'))
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(),
            ];

            $totalSizeMB = round((float) $payload['total_size_bytes'] / (1024 * 1024), 2);
            $payload['total_size_formatted'] = $totalSizeMB . ' MB';

            return $payload;
        });
    }
}
