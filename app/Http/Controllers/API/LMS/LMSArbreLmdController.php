<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\EcrireUnElementLmdRequest;
use App\Services\Lmd\ArbreLmd;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * L'arbre LMD d'une école (#1654) : le lire d'un bloc, créer et modifier un domaine, une mention, un parcours. Le
 * niveau est fixé par la route. Aucune lecture du mode ici : le service passe par la porte du catalogue, et le
 * contrôleur relaie son refus.
 */
final class LMSArbreLmdController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly ArbreLmd $arbre) {}

    public function index(Request $request): JsonResponse
    {
        return $this->rendre(fn (): array => $this->arbre->arbre($request->boolean('actifs')), 200);
    }

    public function store(EcrireUnElementLmdRequest $request): JsonResponse
    {
        return $this->rendre(fn (): array => $this->arbre->creer($request->niveau(), $request->donnees()), 201);
    }

    public function update(EcrireUnElementLmdRequest $request, int $id): JsonResponse
    {
        return $this->rendre(fn (): array => $this->arbre->modifier($request->niveau(), $id, $request->donnees()), 200);
    }

    /** @param Closure(): array<int|string, mixed> $geste */
    private function rendre(Closure $geste, int $statut): JsonResponse
    {
        try {
            $data = $geste();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($data, $statut === 201 ? 'Créé.' : '', $statut);
    }
}
