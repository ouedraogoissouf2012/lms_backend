<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Enums\ElementDeStructure;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ElementDeStructureEnVue;
use App\Http\Requests\EcrireUnElementDeStructureRequest;
use App\Services\Catalogue\StructureDeLEcole;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Créer, modifier, supprimer une filière ou un niveau d'études LOCAL (#1648). L'élément est fixé par la
 * route (`defaults('element', …)`), jamais par le client. Aucune lecture du mode ici : le service passe par
 * la porte du catalogue, et le contrôleur relaie son refus. Les LECTURES restent `GET /proxy/structure`,
 * `/proxy/filieres` et `/proxy/niveaux-etudes` (ADR-EI-01 : même route, autre source).
 */
final class LMSStructureController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly StructureDeLEcole $structure) {}

    public function store(EcrireUnElementDeStructureRequest $request): JsonResponse
    {
        $element = $this->element($request);

        /** @var array{libelle: string, code?: ?string, ordre?: ?int} $donnees */
        $donnees = $request->donnees();

        return $this->rendre(fn (): array => ElementDeStructureEnVue::de($this->structure->creer($element, $donnees)), 201);
    }

    public function update(EcrireUnElementDeStructureRequest $request, int $id): JsonResponse
    {
        $element = $this->element($request);

        return $this->rendre(fn (): array => ElementDeStructureEnVue::de($this->structure->modifier($element, $id, $request->donnees())), 200);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $element = $this->element($request);

        return $this->rendre(function () use ($element, $id): ?array {
            $this->structure->supprimer($element, $id);

            return null;
        }, 200);
    }

    private function element(Request $request): ElementDeStructure
    {
        return ElementDeStructure::from((string) $request->route('element'));
    }

    /** @param Closure(): (array<string, mixed>|null) $geste */
    private function rendre(Closure $geste, int $statut): JsonResponse
    {
        try {
            $data = $geste();
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse($data, $statut === 201 ? 'Créé.' : 'Enregistré.', $statut);
    }
}
