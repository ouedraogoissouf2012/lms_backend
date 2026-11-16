<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\InscriptionParCodeRequest;
use App\Services\Enrollment\InscriptionParCodeService;
use Illuminate\Http\JsonResponse;

/**
 * S'inscrire dans une classe avec un code (#846, ADR-803-03).
 *
 * Route ANONYME : celui qui s'inscrit n'a pas encore de compte. Le code est son
 * autorisation, et le debit est borne par le seau nomme `inscriptions`.
 *
 * La reponse ne rend NI jeton NI session : l'inscrit se connecte ensuite avec le
 * mot de passe qu'il vient de choisir. Emettre un jeton ici ferait de cet
 * endpoint une seconde porte d'authentification, avec sa propre politique.
 */
final class InscriptionParCodeController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly InscriptionParCodeService $inscriptions) {}

    public function store(InscriptionParCodeRequest $request): JsonResponse
    {
        try {
            $classe = $this->inscriptions->inscrire($request->donnees());
        } catch (BusinessException $e) {
            // Le motif départage les 409 des deux portes (#906, ADR-906-01).
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        // La MÊME réponse, que le compte soit créé ou qu'il existât déjà (#914) :
        // rien ne dit lequel des deux, et le message dit à chacun quoi faire.
        return $this->successResponse([
            'classe' => ['id' => $classe->getKey(), 'libelle' => $classe->libelle],
        ], 'Demande enregistree. Si vous n aviez pas de compte, il est cree : connectez-vous avec le mot de passe choisi. '
            .'Si vous aviez deja un compte, connectez-vous avec vos identifiants habituels, puis rejoignez la classe avec ce code.', 202);
    }
}
