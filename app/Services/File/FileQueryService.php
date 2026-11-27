<?php

declare(strict_types=1);

namespace App\Services\File;

use App\Models\File;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * ## Qui lit quoi : une seule règle (#1768)
 *
 * La liste, la fiche et les statistiques passent toutes par
 * {@see LecteurDeFichier::lisibles()} : ce qu'une liste montre, la lecture le
 * rend ; ce que la lecture refuse, aucune liste ni aucun compteur ne le montre.
 *
 * ## Stats — cache par école et par lecteur
 *
 * La gestion d'une école partage une clé (`files_stats_inst_{id}`) ; tout autre
 * membre a la sienne (`files_stats_inst_{id}_user_{id}`) : sa portée dépend de lui.
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
        private readonly LecteurDeFichier $lecteur,
    ) {}

    /**
     * Liste paginée filtrée — appelée par `GET /api/files`.
     *
     * Bornée à ce que l'appelant lit ({@see LecteurDeFichier::lisibles()}).
     * Les filtres `type|category|user_id|fileable_*|sort|per_page` proviennent de
     * `ListFilesRequest::validated()` (déjà whitelistés, cf. issue #10 IDOR).
     *
     * @param  array<string, mixed>  $filters  Validated input from ListFilesRequest.
     * @return LengthAwarePaginator<int, File>
     */
    public function list(User $caller, array $filters): LengthAwarePaginator
    {
        $query = $this->lecteur->lisibles(File::with($this->auteurVisiblePar($caller)), $caller);

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
     * L'auteur d'un fichier n'est rendu qu'au personnel et à son propriétaire
     * (GHSA-gg7j).
     *
     * Un fichier public reste lisible par un élève — c'est du contenu partagé —
     * mais qui l'a déposé est une donnée sur cette personne. La règle est posée
     * sur le CHARGEMENT de la relation : pour qui n'est pas du personnel, `user`
     * vaut `null` sur tout fichier qui n'est pas le sien, en une seule requête
     * et sans second passage sur la liste. Fail-closed : un rôle inconnu est
     * traité comme un élève.
     *
     * @return array<string, Closure(BelongsTo<User, File>): void>
     */
    private function auteurVisiblePar(User $viewer): array
    {
        return ['user' => static function (BelongsTo $relation) use ($viewer): void {
            $relation->select(['users.id', 'users.name', 'users.email', 'users.role']);
            if (! $viewer->isStaff()) {
                $relation->where('users.id', $viewer->id);
            }
        }];
    }

    /**
     * Récupération unitaire d'un fichier — appelée par `GET /api/files/{id}`.
     *
     * Renvoie `null` si l'ID est inconnu OU si l'appelant ne lit pas ce fichier :
     * le caller traduit les deux en un même 404, sans dire si le fichier existe
     * (#1768). Ce que le fichier dit de son AUTEUR dépend aussi de qui regarde :
     * {@see auteurVisiblePar()}.
     */
    public function find(int $id, User $viewer): ?File
    {
        $file = $this->lecteur->lisibles(File::with([...$this->auteurVisiblePar($viewer), 'fileable']), $viewer)
            ->find($id);

        if ($file === null) {
            return null;
        }

        $file->formatted_size = $this->presenter->formattedSize($file);
        $file->download_url = $this->presenter->downloadUrl($file);

        return $file;
    }

    /**
     * Le fichier NU, tel que le téléchargement l'écrit (compteur, date) : sans les
     * attributs d'affichage de {@see find()}, qu'une sauvegarde prendrait pour des
     * colonnes. Même portée, donc même 404 pour un fichier illisible (#1768).
     */
    public function lisible(int $id, User $lecteur): ?File
    {
        return $this->lecteur->lisibles(File::query(), $lecteur)->find($id);
    }

    /**
     * Statistiques agrégées sur les fichiers — appelées par `GET /api/files/stats`.
     *
     * Comptés sur les fichiers que l'appelant LIT ({@see LecteurDeFichier::lisibles()}) ;
     * l'apprenant ne compte que les siens (#964). Un compte sans école ne compte rien.
     *
     * @return array<string, mixed>
     */
    public function stats(User $caller): array
    {
        $cacheKey = $caller->isManager()
            ? "files_stats_inst_{$caller->institution_id}"
            : "files_stats_inst_{$caller->institution_id}_user_{$caller->id}";

        return $this->cache->remember($cacheKey, self::STATS_CACHE_TTL, function () use ($caller): array {
            $payload = [
                'total_files' => $this->comptes(File::query(), $caller)->count(),
                'total_size_bytes' => $this->comptes(File::query(), $caller)->sum('size_bytes'),
                'total_downloads' => $this->comptes(File::query(), $caller)->sum('downloads_count'),
                'by_type' => $this->comptes(File::query(), $caller)
                    ->selectRaw('type, COUNT(*) as count, SUM(size_bytes) as total_size')
                    ->groupBy('type')
                    ->get(),
                'by_category' => $this->comptes(File::query(), $caller)
                    ->selectRaw('category, COUNT(*) as count')
                    ->whereNotNull('category')
                    ->groupBy('category')
                    ->get(),
                'recent_uploads' => $this->comptes(File::with('user:id,name'), $caller)
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(),
            ];

            $totalSizeMB = round((float) $payload['total_size_bytes'] / (1024 * 1024), 2);
            $payload['total_size_formatted'] = $totalSizeMB.' MB';

            return $payload;
        });
    }

    /**
     * La portée des statistiques : ce que l'appelant lit ; l'apprenant, les siens
     * seulement (#964, sa vue par défaut).
     *
     * @param  Builder<File>  $fichiers
     * @return Builder<File>
     */
    private function comptes(Builder $fichiers, User $caller): Builder
    {
        $this->lecteur->lisibles($fichiers, $caller);

        if ($caller->isLearnerAudience()) {
            $fichiers->where('user_id', $caller->id);
        }

        return $fichiers;
    }
}
