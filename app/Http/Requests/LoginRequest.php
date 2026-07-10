<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates login requests (POST /api/auth/login).
 *
 * ## Purpose
 * Authenticate user via local account or KLASSCI federated login.
 * Validates username/email and password before KLASSCI lookup.
 *
 * ## Authorization Model
 * Public endpoint (no authentication required for login attempt).
 *
 * ## 10-year Consideration
 * Password validation rules (min:8) align with KLASSCI minimum.
 * If KLASSCI changes password requirements, update rules() and docs together.
 */
final class LoginRequest extends FormRequest
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
            'username' => 'required|string|max:255',
            'password' => 'required|string|min:8',
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Le nom d\'utilisateur ou email est requis',
            'username.string' => 'Le nom d\'utilisateur doit être une chaîne',
            'password.required' => 'Le mot de passe est requis',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères',
        ];
    }

    /**
     * Helper typé pour récupérer l'username validé en string strict.
     *
     * Issue #120 — Permet aux callers (AuthController) d'éviter `(string)` cast
     * sur `$request->input('username')` qui retourne `mixed`. Pattern conforme
     * §1.6 et utilisé par d'autres FormRequests du projet (cf. `BackfillRoleCommand`).
     */
    public function username(): string
    {
        $value = $this->validated()['username'] ?? '';

        return is_string($value) ? $value : '';
    }

    public function password(): string
    {
        $value = $this->validated()['password'] ?? '';

        return is_string($value) ? $value : '';
    }
}
