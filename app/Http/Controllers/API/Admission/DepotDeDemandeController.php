<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Admission;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeposerDemandeInscriptionRequest;
use App\Services\Admission\DepotDeDemande;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/demandes-inscription` (#1659) : la porte PUBLIQUE, sans jeton. La réponse est TOUJOURS la même, 202, quel
 * que soit le sort de la demande : elle ne confirme ni l'école, ni une adresse ({@see DepotDeDemande}).
 */
final class DepotDeDemandeController extends Controller
{
    use RespondsWithJson;

    public const MESSAGE = 'Demande reçue. Si l\'établissement la retient, il vous recontactera par le téléphone ou le courriel indiqué.';

    public function __construct(private readonly DepotDeDemande $depots) {}

    public function store(DeposerDemandeInscriptionRequest $request): JsonResponse
    {
        $this->depots->deposer($request->string('ecole')->toString(), $request->demande());

        return $this->successResponse(null, self::MESSAGE, 202);
    }
}
