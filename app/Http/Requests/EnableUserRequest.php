<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EnableUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array(auth()->user()->role, ['superAdmin']);
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
