<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ClasseLocaleEnListe;
use App\Http\Requests\ListerClassesLocalesRequest;
use App\Models\Classe;
use App\Services\Catalogue\ClassesLocalesQuery;
use Illuminate\Http\JsonResponse;

/**
 * Lire les classes locales et leur code d'inscription (#905, ADR-905-01).
 *
 * La collection de `POST /classes` : même chemin, même groupe, mêmes rôles.
 * Elle rend le code avec la classe, ce qui suffit à le RELIRE — aucun appel de
 * plus, et surtout aucune régénération, qui invaliderait le code déjà dicté.
 *
 * `effectif` est la CAPACITÉ de la classe (#1115, #1358), que l'école déclare à la
 * création et règle par `PUT /classes/{classe}/capacite` : `null` quand elle n'est pas
 * renseignée (colonne à 0), jamais un zéro qui se lirait « aucune place ».
 */
final class LMSClassesLocalesController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly ClassesLocalesQuery $classes) {}

    public function index(ListerClassesLocalesRequest $request): JsonResponse
    {
        try {
            $page = $this->classes->lister($request->parPage(), $request->statut(), $request->places());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(
            array_map(fn (Classe $classe): array => ClasseLocaleEnListe::de($classe), $page->items()),
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
