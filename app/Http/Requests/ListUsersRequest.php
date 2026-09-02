<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/admin/users` — liste paginée des COMPTES LMS du tenant.
 *
 * Aucun paramètre `with_trashed` : les SoftDeletes excluent déjà les comptes
 * supprimés, et c'est une règle produit — elle n'a pas à être négociable par
 * query string.
 *
 * @see app/Services/User/UserListService.php
 */
final class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // `institution_id === null` = supradmin PLATEFORME. Le refus est ici, et
        // il est structurel : le global scope `BelongsToInstitution` est FAIL-OPEN
        // quand aucun tenant n'est résolu (BelongsToInstitution.php:82-92 journalise
        // un warning puis retourne sans appliquer de `where`). Sans ce refus, un
        // seul appel dumperait l'annuaire de TOUS les établissements. Le rôle
        // plateforme a ses propres écrans cross-tenant (/admin/institutions).
        return $user !== null
            && $user->institution_id !== null
            && ($user->isCoordinator() || $user->isAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `min:2` : une recherche d'un seul caractère balaierait tout l'annuaire.
            'q' => ['sometimes', 'string', 'min:2', 'max:100'],
            'role' => ['sometimes', 'string', function (string $attribute, mixed $value, callable $fail): void {
                if (! is_string($value) || Role::tryFromString($value) === null) {
                    $fail('Rôle inconnu.');
                }
            }],
            // Allow-list de tri : une colonne libre exposerait les autres champs
            // à un oracle par ordonnancement.
            'sort' => ['sometimes', 'in:name,email,role,created_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
