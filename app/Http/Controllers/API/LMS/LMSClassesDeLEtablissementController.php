<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListerClassesDeLEtablissementRequest;
use App\Models\Classe;
use App\Services\Classe\ClassesDeLEtablissementQuery;
use Illuminate\Http\JsonResponse;

/**
 * `GET /lms/classes/etablissement` (#1709) : les classes de l'établissement, `{id, libelle}`,
 * par leur id LOCAL, dans les deux volets. Rien d'autre : le code d'inscription reste à
 * `GET /classes`, que lit qui l'émet.
 */
final class LMSClassesDeLEtablissementController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly ClassesDeLEtablissementQuery $classes) {}

    public function index(ListerClassesDeLEtablissementRequest $request): JsonResponse
    {
        $page = $this->classes->lister($request->etablissement(), $request->parPage());

        return $this->successResponse(
            array_map(
                static fn (Classe $classe): array => ['id' => $classe->getKey(), 'libelle' => $classe->libelle],
                $page->items(),
            ),
            '',
            200,
            [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }
}
