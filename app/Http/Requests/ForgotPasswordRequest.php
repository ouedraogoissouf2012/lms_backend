<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
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
            'email' => ['required', 'email'],
        ];
    }

    /** L'adresse validée (#808), telle que saisie : la connexion la compare telle quelle. */
    public function adresse(): string
    {
        $email = $this->validated('email');

        return is_string($email) ? $email : '';
    }
}
