<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Enums\SchoolRequestStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\DecideSchoolRequestRequest;
use App\Http\Requests\RefuseSchoolRequestRequest;
use App\Models\SchoolRegistrationRequest;
use App\Models\User;
use App\Services\SchoolRegistration\SchoolRequestDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Décision du supradmin plateforme sur une demande d'ouverture (#803).
 *
 * @see docs/adr/2026-09-15-803-02-validation-atomique.md
 */
final class SchoolRequestDecisionController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly SchoolRequestDecisionService $decisions,
    ) {}

    /**
     * La file d'attente du supradmin.
     *
     * Les demandes tranchées ne sont pas listées : elles n'appellent plus de
     * décision, et leur historique se lit par l'institution créée. `usage_prevu`
     * est renvoyé — c'est la seule pièce sur laquelle la décision se prend.
     *
     * PAGINÉE, et ce n'est pas du confort : cette file est alimentée par un
     * endpoint ANONYME. Un throttle borne le débit d'entrée, pas le volume
     * accumulé — une vague de dépôts produirait sinon une réponse sans limite.
     */
    public function index(Request $request): JsonResponse
    {
        $parPage = min(max($request->integer('per_page', 25), 1), 100);

        $page = SchoolRegistrationRequest::query()
            ->where('statut', SchoolRequestStatus::EnAttente->value)
            ->orderBy('created_at')
            ->paginate($parPage, ['id', 'nom_demandeur', 'email_demandeur', 'telephone_demandeur',
                'nom_ecole', 'slug_souhaite', 'usage_prevu', 'created_at']);

        return $this->successResponse($page->items(), '', 200, [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    /**
     * Le lien d'activation est renvoyé UNE FOIS, et n'est stocké nulle part en
     * clair. L'écran doit le présenter comme tel : il ne sera pas réaffiché.
     */
    public function validate(
        DecideSchoolRequestRequest $request,
        SchoolRegistrationRequest $schoolRequest,
    ): JsonResponse {
        try {
            $resultat = $this->decisions->valider(
                $schoolRequest,
                $request->slugImpose(),
                $this->acteur($request),
            );
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $this->statut($e));
        }

        $institution = $resultat->institution;

        return $this->successResponse([
            'institution' => [
                'id' => $institution->getKey(),
                'slug' => $institution->slug,
                'name' => $institution->name,
            ],
            'activation_url' => $resultat->lienActivation,
        ], 'École ouverte. Transmettez le lien d\'activation à son responsable : il ne sera plus affiché.');
    }

    public function refuse(
        RefuseSchoolRequestRequest $request,
        SchoolRegistrationRequest $schoolRequest,
    ): JsonResponse {
        try {
            $this->decisions->refuser($schoolRequest, $request->motif(), $this->acteur($request));
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $this->statut($e));
        }

        return $this->successResponse(null, 'Demande refusée.');
    }

    private function acteur(Request $request): User
    {
        $acteur = $request->user();

        // La route impose `auth:sanctum` : un acteur nul signalerait un
        // middleware retiré, pas un cas métier. On ne le suppose pas résolu.
        if (! $acteur instanceof User) {
            throw new BusinessException('Acteur non résolu.', 401);
        }

        return $acteur;
    }

    private function statut(Throwable $e): int
    {
        $code = $e->getCode();

        return is_int($code) && $code >= 400 && $code < 600 ? $code : 422;
    }
}
