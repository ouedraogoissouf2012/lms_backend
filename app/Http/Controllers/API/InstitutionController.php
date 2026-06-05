<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
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
    ) {
    }

    public function index(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'institutions' => $this->queries->listAllWithStats(),
                    'overview' => $this->queries->getGlobalOverview(),
                ],
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
                : response()->json(['success' => true, 'data' => $result]);
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
                'klassci_api_url' => 'required|url|max:500',
                'klassci_api_token' => 'nullable|string',
                'logo_url' => 'nullable|string|max:500',
                'primary_color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Institution créée avec succès',
                'data' => $institution,
            ], 201);
        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->internalError('store', $e, 'Erreur lors de la création de l\'institution');
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $institution = $this->crud->update($id, $request->validate([
                'slug' => ['sometimes', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/', Rule::unique('institutions', 'slug')->ignore($id)],
                'name' => 'sometimes|string|max:191',
                'klassci_api_url' => 'sometimes|url|max:500',
                'klassci_api_token' => 'nullable|string',
                'logo_url' => 'nullable|string|max:500',
                'primary_color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Institution mise à jour avec succès',
                'data' => $institution,
            ]);
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

            return response()->json([
                'success' => true,
                'message' => $institution->is_active ? 'Institution activée' : 'Institution désactivée',
                'data' => $institution,
            ]);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (Throwable $e) {
            return $this->internalError('toggle', $e, 'Erreur lors du changement de statut', $id);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->crud->softDelete($id);

            return response()->json(['success' => true, 'message' => 'Institution supprimée avec succès']);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (Throwable $e) {
            return $this->internalError('destroy', $e, 'Erreur lors de la suppression', $id);
        }
    }

    public function testConnection(int $id): JsonResponse
    {
        try {
            $result = $this->connectionTester->test($id);

            return response()->json($result['payload'], $result['status']);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (BusinessException $e) {
            return $this->businessError($e->getMessage());
        } catch (ConnectionException) {
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
        return response()->json(['success' => false, 'message' => 'Institution non trouvée'], 404);
    }

    private function businessError(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Erreur de validation',
            'errors' => $e->errors(),
        ], 422);
    }

    private function internalError(string $method, Throwable $e, string $message, ?int $id = null): JsonResponse
    {
        $context = ['error' => $e->getMessage()];
        if ($id !== null) {
            $context['id'] = $id;
        }
        $this->logger->error('InstitutionController@' . $method, $context);

        return response()->json(['success' => false, 'message' => $message], 500);
    }
}
