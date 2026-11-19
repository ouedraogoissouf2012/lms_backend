<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Auth\TenantAwareTokenRepository;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Réinitialisation locale scopée au tenant (#714), par un jeton.
 *
 * #808 : la demande de lien ne crée plus de jeton, faute de canal pour le délivrer
 * ({@see MotDePasseOublie}). La réinitialisation par jeton reste en place pour le jour où
 * l'envoi existera.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly Hasher $hash,
        private readonly TenantAwareTokenRepository $tokens,
    ) {}

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
