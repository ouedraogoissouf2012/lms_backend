<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Institution\InstitutionDirectoryService;
use Illuminate\Http\JsonResponse;

/**
 * Endpoints PUBLICS de l'annuaire des institutions (issue #375).
 *
 * Remplace les closures historiques de `routes/api/core.php` à contrat JSON
 * strictement identique (golden master :
 * {@see \Tests\Feature\Api\PublicCoreRoutesContractTest}). Le frontend
 * (`lms-frontend/src/stores/auth.js`) consomme `/institutions/active` pour le
 * sélecteur de la page de login — avant toute authentification.
 *
 * Distinct de {@see InstitutionController} (admin supradmin, cross-tenant) :
 * ne JAMAIS fusionner les deux, leurs services ont des périmètres de sécurité
 * opposés.
 */
final class InstitutionDirectoryController extends Controller
{
    public function __construct(
        private readonly InstitutionDirectoryService $directory,
    ) {
    }

    /** GET /institutions/active — liste publique pour le sélecteur de login. */
    public function active(): JsonResponse
    {
        return $this->successResponse($this->directory->listActive());
    }

    /** GET /institution/current — branding du tenant résolu par X-Institution. */
    public function current(): JsonResponse
    {
        $descriptor = $this->directory->currentDescriptor();

        if ($descriptor === null) {
            return $this->errorResponse('Aucune institution résolue', 400);
        }

        return $this->successResponse($descriptor);
    }
}
