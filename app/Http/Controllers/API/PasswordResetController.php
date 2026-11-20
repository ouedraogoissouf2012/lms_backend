<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\Auth\MotDePasseOublie;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

/**
 * Réinitialisation publique : tenant via X-Institution seulement (#714).
 */
final class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $resets,
    ) {}

    /**
     * #808 : la réponse dépend de l'école et du canal, jamais du compte. L'adresse va à
     * l'école autonome, qui la confie à la tâche d'envoi sans la chercher elle-même.
     */
    public function forgot(ForgotPasswordRequest $request, MotDePasseOublie $motDePasseOublie): JsonResponse
    {
        return $this->relayResponse($motDePasseOublie->reponse($request->adresse()));
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        /** @var array{email: string, token: string, password: string, password_confirmation: string} $credentials */
        $credentials = $request->validated();
        $result = $this->resets->reset($credentials);

        return $this->relayResponse($result);
    }
}
