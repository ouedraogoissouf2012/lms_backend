<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesQueryBooleans;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /notifications query parameters — issue #548.
 */
final class ListNotificationsRequest extends FormRequest
{
    use NormalizesQueryBooleans;

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
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100', // anti-DOS (#548)
            ],
            'unread_only' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'per_page.max' => 'per_page ne peut pas dépasser 100.',
        ];
    }

    /**
     * `unread_only` arrive par la chaîne de requête, donc en TEXTE : la règle
     * `boolean` de Laravel refusait `'false'` et rendait un 422 à chaque
     * chargement du tableau de bord. Voir {@see NormalizesQueryBooleans} pour
     * la mesure et le raisonnement.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeQueryBooleans(['unread_only']);
    }
}
