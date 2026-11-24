<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Services\Auth\MotDePasseOublie;
use Illuminate\Http\JsonResponse;

/**
 * « Mot de passe oublié », public : tenant via X-Institution seulement (#714).
 *
 * #1306 : la réinitialisation elle-même n'est plus ici. Le lien envoyé est le jeton
 * d'activation (#808), consommé par `ActivationController`.
 */
final class PasswordResetController extends Controller
{
    /**
     * #808 : la réponse dépend de l'école et du canal, jamais du compte. L'adresse va à
     * l'école autonome, qui la confie à la tâche d'envoi sans la chercher elle-même.
     */
    public function forgot(ForgotPasswordRequest $request, MotDePasseOublie $motDePasseOublie): JsonResponse
    {
        return $this->relayResponse($motDePasseOublie->reponse($request->adresse()));
    }
}
