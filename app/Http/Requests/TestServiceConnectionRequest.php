<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

final class TestServiceConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->asRoleEnum() === Role::Supradmin;
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
