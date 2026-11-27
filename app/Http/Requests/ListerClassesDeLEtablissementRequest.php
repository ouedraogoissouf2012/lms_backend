<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Pagination des classes de l'établissement (#1709) : les règles communes de
 * {@see ListePagineeRequest}.
 *
 * Le rôle est vérifié par la garde `gestion` de la route. L'établissement l'est ici, au
 * point d'appel : la porte de l'API refuse déjà un compte sans école, et cette requête ne
 * dépend pas de cet ordre pour ne rien lister hors d'une école.
 */
final class ListerClassesDeLEtablissementRequest extends ListePagineeRequest
{
    public function authorize(): bool
    {
        return is_int($this->user()?->institution_id);
    }

    public function etablissement(): int
    {
        return (int) $this->user()?->institution_id;
    }
}
