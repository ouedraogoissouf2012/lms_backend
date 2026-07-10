<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExportUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        return $user !== null && ($user->isCoordinator() || $user->isAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => 'required|string|in:csv,xlsx',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'format.in' => 'Le format doit être CSV ou XLSX.',
        ];
    }
}
