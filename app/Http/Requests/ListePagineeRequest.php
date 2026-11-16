<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La pagination commune des listes que lit l'écran de gestion : les classes
 * locales (#905) et les Périodes (#911).
 *
 * `authorize()` rend `true` : le rôle est vérifié par le groupe de la route, et
 * le droit sur les données par le service.
 *
 * ## Refusée hors bornes, jamais ramenée
 *
 * La spec approuvée de #548 (`.claude/specs/548-per-page-bounds-throttle/design.md`
 * §1) écarte nommément le « clamp silencieux » : il masquerait au client un
 * appel fautif. Tout le dépôt suit donc le 422 — `AttendanceHistoryRequest`,
 * `ListNotificationsRequest`. #905 avait d'abord ramené la valeur dans ses
 * bornes, sur le modèle de `TrainingSessionController:57` ; #924 le corrige, et
 * #911 corrige le modèle lui-même.
 */
abstract class ListePagineeRequest extends FormRequest
{
    public const PAR_PAGE_DEFAUT = 25;

    public const PAR_PAGE_MAX = 100;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::PAR_PAGE_MAX], // anti-DOS (#548)
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'per_page.max' => 'per_page ne peut pas dépasser '.self::PAR_PAGE_MAX.'.',
        ];
    }

    /**
     * La taille, ou le défaut si elle est absente. Lue APRÈS validation : ce
     * n'est plus l'`intval` silencieux qu'un `?per_page=abc` rendait 0 — la
     * règle `integer` l'a déjà refusé. Même lecture que `NotificationsController:59`
     * derrière `ListNotificationsRequest`.
     */
    public function parPage(): int
    {
        return $this->integer('per_page', self::PAR_PAGE_DEFAUT);
    }
}
