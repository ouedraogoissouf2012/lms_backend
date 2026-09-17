<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Enums\InstitutionMode;
use App\Exceptions\BusinessException;
use App\Exceptions\UnserializablePayloadException;
use App\Http\Controllers\Controller;
use App\Rules\KlassciApiUrl;
use App\Services\Institution\InstitutionConnectionTester;
use App\Services\Institution\InstitutionCrudService;
use App\Services\Institution\InstitutionQueryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Admin platform-wide (supradmin only). SECURITY: services agrègent cross-tenant
 * via `withoutGlobalScope('institution')` — ne JAMAIS ouvrir à un autre rôle.
 */
final class InstitutionController extends Controller
{
    public function __construct(
        private readonly InstitutionQueryService $queries,
        private readonly InstitutionCrudService $crud,
        private readonly InstitutionConnectionTester $connectionTester,
        private readonly LoggerInterface $logger,
    ) {}

    public function index(): JsonResponse
    {
        try {
            return $this->successResponse([
                'institutions' => $this->queries->listAllWithStats(),
                'overview' => $this->queries->getGlobalOverview(),
            ]);
        } catch (Throwable $e) {
            return $this->internalError('index', $e, 'Erreur lors de la récupération des institutions');
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $result = $this->queries->getOneWithStats($id);

            return $result === null
                ? $this->notFound()
                : $this->successResponse($result);
        } catch (Throwable $e) {
            return $this->internalError('show', $e, 'Erreur lors de la récupération de l\'institution', $id);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $institution = $this->crud->create($request->validate([
                'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/', 'unique:institutions,slug'],
                'name' => 'required|string|max:191',
                // Déclaré à la naissance, où il n'y a encore personne à déplacer.
                // Absent, le modèle retombe sur `klassci` (#814) : aucune école
                // existante ne change de comportement.
                'mode' => ['sometimes', Rule::enum(InstitutionMode::class)],
                'klassci_api_url' => ['nullable', 'string', 'max:500', new KlassciApiUrl],
                'klassci_api_token' => 'nullable|string',
                'logo_url' => 'nullable|string|max:500',
                'primary_color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
            ]));

            return $this->successResponse($institution, 'Institution créée avec succès', 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->internalError('store', $e, 'Erreur lors de la création de l\'institution');
        }
    }

    /**
     * `mode` est VOLONTAIREMENT absent de ces règles, et doit le rester.
     *
     * Basculer le mode change l'autorité d'inscription de tout l'établissement.
     * Le laisser passer ici en ferait l'effet de bord possible d'un changement
     * de couleur ou de logo. La bascule a sa propre route ({@see changeMode}),
     * comme `is_active` a déjà la sienne ({@see toggle}).
     *
     * Une clé `mode` envoyée dans ce `PUT` n'est pas rejetée : elle est
     * simplement absente du tableau validé, donc jamais transmise au service.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $institution = $this->crud->update($id, $request->validate([
                'slug' => ['sometimes', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/', Rule::unique('institutions', 'slug')->ignore($id)],
                'name' => 'sometimes|string|max:191',
                'klassci_api_url' => ['sometimes', 'string', 'max:500', new KlassciApiUrl],
                'klassci_api_token' => 'nullable|string',
                'logo_url' => 'nullable|string|max:500',
                'primary_color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
            ]));

            return $this->successResponse($institution, 'Institution mise à jour avec succès');
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->internalError('update', $e, 'Erreur lors de la mise à jour', $id);
        }
    }

    public function toggle(int $id): JsonResponse
    {
        try {
            $institution = $this->crud->toggleActive($id);

            return $this->successResponse(
                $institution,
                $institution->is_active ? 'Institution activée' : 'Institution désactivée',
            );
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (Throwable $e) {
            return $this->internalError('toggle', $e, 'Erreur lors du changement de statut', $id);
        }
    }

    /**
     * Bascule délibérée du mode d'un établissement (#818).
     *
     * Le mode voulu est exigé dans le corps plutôt qu'inversé comme le fait
     * `toggle` : avec deux valeurs aujourd'hui et une troisième possible demain,
     * une inversion implicite deviendrait un piège. Le dire, c'est le vouloir.
     */
    public function changeMode(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'mode' => ['required', Rule::enum(InstitutionMode::class)],
            ]);

            $institution = $this->crud->changeMode($id, InstitutionMode::from((string) $validated['mode']));

            return $this->successResponse($institution, 'Mode de l\'institution mis à jour');
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->internalError('changeMode', $e, 'Erreur lors du changement de mode', $id);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->crud->softDelete($id);

            return $this->successResponse(null, 'Institution supprimée avec succès');
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (BusinessException $e) {
            // #567 : refus de supprimer une institution encore active (422),
            // message métier safe par contrat.
            return $this->businessError($e->getMessage());
        } catch (Throwable $e) {
            return $this->internalError('destroy', $e, 'Erreur lors de la suppression', $id);
        }
    }

    public function testConnection(int $id): JsonResponse
    {
        try {
            $result = $this->connectionTester->test($id);

            // Payload BRUT du service (clés racine variables selon le résultat du
            // test KLASSCI) : relayé verbatim, sans enveloppe contrôlée
            // (axe #1, règle MIXTE).
            //
            // Le commentaire disait « RACINE laissée inline » — c'était vrai tant
            // que la ligne était un `response()->json()` en dur. #693 l'a rendu
            // faux sans le corriger : relevé par la revue, corrigé ici.
            return $this->relayResponse($result);
        } catch (UnserializablePayloadException $e) {
            // #693 — la garde de sérialisabilité ne doit PAS être avalée.
            // Elle étend `LogicException`, donc `Throwable` : sans ce catch, elle
            // tombait dans le fourre-tout plus bas, était journalisée sous une
            // cause fausse et rendue en 500 générique. C'est une erreur de
            // programmation, pas une panne métier.
            throw $e;
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (ConnectionException) {
            // Non migré vers errorResponse() : porte une clé `data` sur une réponse
            // d'erreur, que le trait ne reproduit pas (il n'émet que `errors`).
            // Conservé inline (axe #1 « DRY-only »).
            return response()->json([
                'success' => false,
                'message' => 'Impossible de se connecter au serveur KLASSCI',
                'data' => ['error' => 'Erreur de connexion au serveur KLASSCI.'],
            ], 502);
        } catch (Throwable $e) {
            return $this->internalError('testConnection', $e, 'Erreur lors du test de connexion', $id);
        }
    }

    private function notFound(): JsonResponse
    {
        return $this->errorResponse('Institution non trouvée', 404);
    }

    private function businessError(string $message): JsonResponse
    {
        return $this->errorResponse($message, 422);
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return $this->errorResponse('Erreur de validation', 422, $e->errors());
    }

    private function internalError(string $method, Throwable $e, string $message, ?int $id = null): JsonResponse
    {
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return $this->errorResponse('Requête invalide', $e->getStatusCode());
        }

        $context = ['error' => $e->getMessage()];
        if ($id !== null) {
            $context['id'] = $id;
        }
        $this->logger->error('InstitutionController@'.$method, $context);

        return $this->errorResponse($message, 500);
    }
}
