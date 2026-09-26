<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Enums\EtatCodeInscription;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListerClassesLocalesRequest;
use App\Models\Classe;
use App\Services\Catalogue\ClassesLocalesQuery;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Lire les classes locales et leur code d'inscription (#905, ADR-905-01).
 *
 * La collection de `POST /classes` : même chemin, même groupe, mêmes rôles.
 * Elle rend le code avec la classe, ce qui suffit à le RELIRE — aucun appel de
 * plus, et surtout aucune régénération, qui invaliderait le code déjà dicté.
 *
 * Pas d'`effectif` : pour une classe locale, cette colonne vaut toujours 0 —
 * seul le synchroniseur KLASSCI la tient. L'exposer ferait afficher un zéro
 * faux.
 */
final class LMSClassesLocalesController extends Controller
{
    use RespondsWithJson;

    public function __construct(private readonly ClassesLocalesQuery $classes) {}

    public function index(ListerClassesLocalesRequest $request): JsonResponse
    {
        try {
            $page = $this->classes->lister($request->parPage());
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $this->statut($e));
        }

        return $this->successResponse(
            array_map(fn (Classe $classe): array => $this->enVue($classe), $page->items()),
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

    /**
     * @return array<string, mixed>
     */
    private function enVue(Classe $classe): array
    {
        return [
            'id' => $classe->getKey(),
            'libelle' => $classe->libelle,
            'code' => $classe->code,
            'training_session_id' => $classe->training_session_id,
            'code_inscription' => $this->codeInscription($classe),
        ];
    }

    /**
     * @return array{etat: string, valeur: ?string}
     */
    private function codeInscription(Classe $classe): array
    {
        $etat = EtatCodeInscription::depuis($classe->code_inscription, $classe->code_inscription_revoque_le);

        return ['etat' => $etat->value, 'valeur' => $etat->valeurVisible($classe->code_inscription)];
    }

    private function statut(Throwable $e): int
    {
        $code = $e->getCode();

        return is_int($code) && $code >= 400 && $code < 600 ? $code : 422;
    }
}
