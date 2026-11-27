<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Dossier;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\EcrirePieceAFournirRequest;
use App\Models\PieceAFournir;
use App\Services\Dossier\CatalogueDesPieces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le catalogue des pièces à fournir (#1661, PR 1), gestion d'une école autonome. Les règles vivent dans
 * {@see CatalogueDesPieces}.
 */
final class PieceAFournirController extends AuthenticatedController
{
    public function __construct(private readonly CatalogueDesPieces $pieces) {}

    public function index(Request $request): JsonResponse
    {
        $enService = $request->has('en_service') ? $request->boolean('en_service') : null;

        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->pieces->lister($this->authenticatedUser($request), $enService)->map(fn (PieceAFournir $p): array => $this->forme($p))->values()->all(),
        ));
    }

    public function store(EcrirePieceAFournirRequest $request): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->forme($this->pieces->creer($this->authenticatedUser($request), $request->validated())),
            'Pièce ajoutée au catalogue',
            201,
        ));
    }

    public function update(EcrirePieceAFournirRequest $request, int $piece): JsonResponse
    {
        return $this->traduire(fn (): JsonResponse => $this->successResponse(
            $this->forme($this->pieces->modifier($this->authenticatedUser($request), $piece, $request->validated())),
            'Pièce mise à jour',
        ));
    }

    /** @return array<string, mixed> */
    private function forme(PieceAFournir $p): array
    {
        return [
            'id' => $p->id, 'code' => $p->code, 'libelle' => $p->libelle, 'consigne' => $p->consigne,
            'forme' => $p->forme, 'quantite' => $p->quantite, 'portee' => $p->portee, 'validite_jours' => $p->validite_jours,
            'obligatoire' => $p->obligatoire, 'en_service' => $p->en_service,
        ];
    }

    /** @param  callable(): JsonResponse  $acte */
    private function traduire(callable $acte): JsonResponse
    {
        try {
            return $acte();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }
    }
}
