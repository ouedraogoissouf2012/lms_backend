<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pagination de la liste des classes locales (#905).
 *
 * `authorize()` rend `true` : le rôle est vérifié par le groupe de la route, et
 * le droit sur le catalogue par le service.
 *
 * ## Ramenée dans ses bornes, jamais refusée
 *
 * C'est le contrat de la liste des Périodes, que le même écran lit. Mais le
 * bornage en ligne qu'elle emploie (`TrainingSessionController:57`) a un défaut
 * que celui-ci corrige : `Request::integer()` fait un `intval`, et
 * `?per_page=abc` y devient 0, borné à 1 — une classe par page, en silence, au
 * lieu des 25 par défaut. Ici, une valeur non numérique vaut le défaut.
 */
final class ListerClassesLocalesRequest extends FormRequest
{
    public const PAR_PAGE_DEFAUT = 25;

    public const PAR_PAGE_MAX = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aucune règle, et c'est le contrat : rien de cette requête ne se refuse.
     * `page` est lu par le paginateur de Laravel, qui ramène toute valeur non
     * entière à 1 ; `per_page` est normalisé par {@see self::parPage()}.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function parPage(): int
    {
        $brut = $this->query('per_page');

        if (! is_string($brut) || ! is_numeric($brut)) {
            return self::PAR_PAGE_DEFAUT;
        }

        return min(max((int) $brut, 1), self::PAR_PAGE_MAX);
    }
}
