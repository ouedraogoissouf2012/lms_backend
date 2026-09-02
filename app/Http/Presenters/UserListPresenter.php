<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Projection JSON de la liste des comptes LMS.
 *
 * Allow-list EXPLICITE, sur le modèle de {@see AuthResponsePresenter}. Jamais de
 * modèle sérialisé tel quel, jamais de `->load('institution')` : sérialiser un
 * `User` exposerait `klassci_tenant_url`, `klassci_role`, `klassci_data`,
 * `institution_id`, `last_klassci_sync`… autant de détails internes qu'un écran
 * d'annuaire n'a aucune raison de connaître. Ajouter une colonne à la table ne
 * peut donc pas élargir la réponse par inadvertance.
 *
 * @see app/Services/User/UserListService.php
 */
final class UserListPresenter
{
    /**
     * @param  LengthAwarePaginator<int, User>  $page
     * @return array<string, mixed>
     */
    public function paginated(LengthAwarePaginator $page): array
    {
        $items = [];
        foreach ($page->items() as $user) {
            $items[] = $this->item($user);
        }

        return [
            'data' => $items,
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'klassci_id' => $user->klassci_id,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
