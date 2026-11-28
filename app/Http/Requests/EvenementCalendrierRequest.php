<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TypeDEvenement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /lms/calendrier` et `PUT /lms/calendrier/{id}` (#1671) : le type (énumération fermée), le titre, les jours de
 * début et de fin, et la portée (`classe_ids` ; vide ou absent : toute l'école). Aucune règle `exists` sur les
 * classes : elle validerait l'existence GLOBALE ; le service les cherche dans l'école (422 `classe_hors_ecole`).
 */
final class EvenementCalendrierRequest extends FormRequest
{
    public const CLASSES_MAX = 200;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TypeDEvenement::class)],
            'titre' => ['required', 'string', 'max:150'],
            'debut' => ['required', 'date_format:Y-m-d'],
            'fin' => ['required', 'date_format:Y-m-d', 'after_or_equal:debut'],
            'classe_ids' => ['sometimes', 'array', 'max:'.self::CLASSES_MAX],
            'classe_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    /** @return array{type: string, titre: string, debut: string, fin: string, classe_ids: list<int>} */
    public function champs(): array
    {
        $classes = [];
        foreach ((array) $this->input('classe_ids', []) as $id) {
            if (is_numeric($id)) {
                $classes[] = (int) $id;
            }
        }

        return [
            'type' => $this->string('type')->toString(),
            'titre' => $this->string('titre')->trim()->toString(),
            'debut' => $this->string('debut')->toString(),
            'fin' => $this->string('fin')->toString(),
            'classe_ids' => $classes,
        ];
    }
}
