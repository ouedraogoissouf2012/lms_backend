<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /notifications/recent query parameters — issue #548.
 *
 * Widget dashboard : liste courte, plafond plus strict que les listings
 * classiques (20 au lieu de 100).
 */
final class RecentNotificationsRequest extends FormRequest
{
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
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:20', // anti-DOS (#548) — widget dashboard, liste courte
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'limit.max' => 'limit ne peut pas dépasser 20.',
        ];
    }
}
