<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\DefinirLeMotDePasseRequest;
use App\Models\User;
use App\Services\User\DefinitionDuMotDePasse;
use Illuminate\Http\JsonResponse;

/**
 * `PUT /api/users/{user}/password` (#1275) : l'administration de l'école définit le mot de
 * passe d'un compte. La source dépend de l'école : reçue par la MÉTHODE, jamais par le
 * constructeur (Laravel garde le contrôleur sur la route).
 */
final class MotDePasseDuCompteController extends Controller
{
    public function definir(DefinirLeMotDePasseRequest $request, User $user, DefinitionDuMotDePasse $definition): JsonResponse
    {
        $motDePasse = $request->validated('password');

        try {
            $definition->definir($user, is_string($motDePasse) ? $motDePasse : '');
        } catch (BusinessException $e) {
            // École KLASSCI : le motif dit où le mot de passe se change (ADR-906-01).
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(null, 'Mot de passe défini.');
    }
}
