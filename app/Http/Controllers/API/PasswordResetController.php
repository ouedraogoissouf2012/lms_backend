<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
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

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $result = $this->resets->requestLink(is_string($email) ? $email : '');

        return response()->json($result['payload'], $result['status']);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        /** @var array{email: string, token: string, password: string, password_confirmation: string} $credentials */
        $credentials = $request->validated();
        $result = $this->resets->reset($credentials);

        return response()->json($result['payload'], $result['status']);
    }
}
