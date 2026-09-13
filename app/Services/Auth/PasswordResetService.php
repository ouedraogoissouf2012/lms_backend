<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Auth\TenantAwareTokenRepository;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Réinitialisation locale scopée au tenant (#714).
 */
final class PasswordResetService
{
    public function __construct(
        private readonly Hasher $hash,
        private readonly TenantAwareTokenRepository $tokens,
    ) {}

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function requestLink(string $email): array
    {
        $user = User::query()->where('email', $email)->first();
        if ($user instanceof User && ($user->getEmailForPasswordReset() === '')) {
            return [
                'status' => 422,
                'payload' => [
                    'success' => false,
                    'message' => 'Ce compte n\'a pas d\'adresse courriel : la réinitialisation est impossible.',
                ],
            ];
        }

        if ($user instanceof User) {
            $this->tokens->create($user);
        }

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => 'Si un compte existe pour cette adresse, un courriel a été envoyé.',
            ],
        ];
    }

    /**
     * @param  array{email: string, token: string, password: string, password_confirmation: string}  $credentials
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function reset(array $credentials): array
    {
        $user = User::query()->where('email', $credentials['email'])->first();
        if (! $user instanceof User || ! $this->tokens->exists($user, $credentials['token'])) {
            return [
                'status' => 422,
                'payload' => [
                    'success' => false,
                    'message' => 'Jeton invalide ou expiré.',
                ],
            ];
        }

        $user->forceFill([
            'password' => $this->hash->make($credentials['password']),
        ])->save();
        $this->tokens->delete($user);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => 'Mot de passe mis à jour.',
            ],
        ];
    }
}
