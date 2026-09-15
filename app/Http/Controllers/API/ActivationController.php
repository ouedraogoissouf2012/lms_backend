<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\ActivateAccountRequest;
use App\Services\Activation\ActivationTokenService;
use Illuminate\Http\JsonResponse;

/**
 * Activation d'un compte par lien à usage unique (#803, ADR-803-02).
 *
 * Route anonyme : celui qui active n'a pas encore de quoi s'authentifier.
 *
 * @see docs/adr/2026-09-15-803-02-validation-atomique.md
 */
final class ActivationController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly ActivationTokenService $activations,
    ) {}

    /**
     * Un jeton inconnu, déjà consommé ou périmé rend le MÊME 410.
     *
     * Les distinguer dirait à un inconnu qu'un lien a existé pour cette valeur,
     * et donc qu'un compte l'attend. Le 410 « Gone » est choisi plutôt qu'un 404
     * parce qu'il décrit exactement le cas dominant : le lien a bien existé,
     * il ne vaut plus.
     */
    public function store(ActivateAccountRequest $request): JsonResponse
    {
        $user = $this->activations->consommer($request->jeton());

        if ($user === null) {
            return $this->errorResponse(
                'Ce lien n\'est plus valable. Demandez-en un nouveau à votre administrateur.',
                410,
            );
        }

        // Le cast `hashed` du modèle chiffre la valeur : on ne la hache pas ici,
        // sous peine de double empreinte.
        $user->password = $request->motDePasse();
        $user->save();

        return $this->successResponse(null, 'Votre mot de passe est enregistré. Vous pouvez vous connecter.');
    }
}
