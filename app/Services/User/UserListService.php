<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Liste paginée des comptes LMS d'un tenant, pour l'écran d'administration.
 *
 * Ne lit QUE la base LMS : aucun appel KLASSCI sur ce chemin. C'est tout l'objet
 * de l'écran — montrer les comptes qui peuvent réellement se connecter, y compris
 * coordinateurs et administrateurs, que le proxy KLASSCI ne renvoie pas.
 *
 * @see app/Http/Requests/ListUsersRequest.php
 */
final class UserListService
{
    /** Colonnes de tri autorisées (l'allow-list vit aussi dans la FormRequest). */
    private const SORTABLE = ['name', 'email', 'role', 'created_at'];

    /**
     * Allow-list de PROJECTION : ajouter une colonne à `users` ne peut pas
     * élargir la surface de l'API par inadvertance.
     *
     * @var list<string>
     */
    private const PROJECTION = ['id', 'name', 'email', 'role', 'klassci_id', 'created_at'];

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $sortInput = $filters['sort'] ?? null;
        $sort = is_string($sortInput) && in_array($sortInput, self::SORTABLE, true) ? $sortInput : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $perPageInput = $filters['per_page'] ?? null;
        $perPage = is_numeric($perPageInput) ? (int) $perPageInput : 25;

        return $this->query($actor, $filters)
            ->select(self::PROJECTION)
            ->orderBy($sort, $direction)
            // Départage stable : sans clé totale, deux comptes de même nom peuvent
            // se répéter d'une page à l'autre, ou disparaître entre deux.
            ->orderBy('id')
            ->paginate(min($perPage, 100));
    }

    /**
     * Effectifs par famille de rôle, en UNE requête agrégée.
     *
     * @return array{total: int, etudiants: int, enseignants: int, administration: int}
     */
    public function counts(User $actor): array
    {
        $rows = $this->query($actor, [])
            ->selectRaw('role, COUNT(*) as aggregate_count')
            ->groupBy('role')
            ->get();

        /** @var array<string, int> $countByRole */
        $countByRole = [];
        foreach ($rows as $row) {
            $role = $row->getAttribute('role');
            $count = $row->getAttribute('aggregate_count');
            if (is_string($role) && is_numeric($count)) {
                $countByRole[$role] = (int) $count;
            }
        }

        $sumOf = static function (Role $role) use ($countByRole): int {
            $total = 0;
            foreach ($role->aliases() as $alias) {
                $total += $countByRole[$alias] ?? 0;
            }

            return $total;
        };

        $etudiants = $sumOf(Role::Etudiant);
        $enseignants = $sumOf(Role::Enseignant);
        $total = array_sum($countByRole);

        return [
            'total' => $total,
            'etudiants' => $etudiants,
            'enseignants' => $enseignants,
            // Tout le reste : coordinateurs, admins, superAdmins. Déduit du total,
            // pour qu'aucun rôle ne manque à l'appel si l'enum s'enrichit.
            'administration' => $total - $etudiants - $enseignants,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    private function query(User $actor, array $filters): Builder
    {
        // Filtre tenant EXPLICITE : on ne s'appuie pas sur le seul global scope,
        // fail-open sans tenant résolu (cas (b) documenté dans
        // BelongsToInstitution.php). Défense en profondeur, pas redondance.
        $query = User::query()->where('institution_id', $actor->institution_id);

        $roleInput = $filters['role'] ?? null;
        $role = is_string($roleInput) ? Role::tryFromString($roleInput) : null;
        if ($role !== null) {
            // Alias inclus : `etudiant` doit aussi matcher `student` / `étudiant`,
            // variantes réellement présentes en base selon l'époque du sync.
            $query->whereIn('role', $role->aliases());
        }

        $term = $filters['q'] ?? null;
        if (is_string($term) && $term !== '') {
            $this->applySearch($query, $term);
        }

        return $query;
    }

    /**
     * Recherche sur nom et email, caractères joker NEUTRALISÉS.
     *
     * Sans échappement, un `%` saisi ramènerait tout l'annuaire et un `_` servirait
     * d'oracle caractère par caractère. `ESCAPE '!'` est portable MySQL/SQLite.
     *
     * @param  Builder<User>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $pattern = '%'.self::escapeLike($term).'%';
        $grammar = $query->getQuery()->getGrammar();
        $name = $grammar->wrap('name');
        $email = $grammar->wrap('email');

        $query->where(static fn (Builder $sub): Builder => $sub
            ->whereRaw($name.' LIKE ? ESCAPE ?', [$pattern, '!'])
            ->orWhereRaw($email.' LIKE ? ESCAPE ?', [$pattern, '!']));
    }

    /** Le `!` doit être doublé EN PREMIER, sinon il ré-échapperait les suivants. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
