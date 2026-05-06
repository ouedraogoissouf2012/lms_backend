<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DeleteConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->role === 'superAdmin';
    }

    public function rules(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [];
    }
}
