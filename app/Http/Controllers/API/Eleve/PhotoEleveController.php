<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Eleve;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeposerPhotoRequest;
use App\Services\Eleve\PhotoDeLEleve;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * La photo de l'élève (#1657, PR 2). Les règles vivent dans {@see PhotoDeLEleve} : dépôt ré-encodé, stockage privé,
 * lecture selon les règles du dossier. La photo n'est servie que par cette route, jamais par une URL publique.
 */
final class PhotoEleveController extends AuthenticatedController
{
    public function __construct(private readonly PhotoDeLEleve $photos) {}

    public function show(Request $request, int $eleve): Response
    {
        try {
            $chemin = $this->photos->chemin($this->authenticatedUser($request), $eleve);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        // Une image de dossier : jamais en cache partagé, ni indexée.
        return $this->photos->disque()->response($chemin, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(DeposerPhotoRequest $request, int $eleve): JsonResponse
    {
        $fichier = $request->file('photo');
        if (! $fichier instanceof UploadedFile) {
            return $this->errorResponse('Aucune photo reçue.', 422);
        }

        try {
            $premiere = $this->photos->deposer($this->authenticatedUser($request), $eleve, $fichier);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(['photo_url' => "/api/eleves/{$eleve}/photo"], 'Photo enregistrée', $premiere ? 201 : 200);
    }

    public function destroy(Request $request, int $eleve): JsonResponse
    {
        try {
            $this->photos->retirer($this->authenticatedUser($request), $eleve);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->statutHttp(), reason: $e->reason);
        }

        return $this->successResponse(null, 'Photo retirée');
    }
}
