<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use App\Http\Controllers\API\Proxy\Concerns\ResolvesPersonalKlassciToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Classe\InstitutionClassesReader;
use App\Services\Klassci\TeacherDirectory\LocalTeacherDirectory;
use App\Services\Klassci\TeacherDirectory\TeacherDirectoryVisibility;
use App\Services\KlassciProxyService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Proxy KLASSCI — Lecture organisationnelle (GET only).
 *
 * Structure, classes, matières, enseignants, filières, niveaux, test connexion.
 */
final class ProxyOrganisationController extends Controller
{
    use RendersKlassciProxyErrors;
    use ResolvesPersonalKlassciToken;

    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly TeacherDirectoryVisibility $visibility,
        private readonly LocalTeacherDirectory $localDirectory,
    ) {
    }

    /**
     * GET /api/proxy/structure — Structure organisationnelle (filières, niveaux).
     */
    public function structure(): JsonResponse
    {
        try {
            $data = $this->klassciService->getStructure();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/classes — les classes de l'établissement.
     *
     * Même route, autre source (ADR-EI-01, lot 3a) : KLASSCI relayé dans une
     * école créée depuis KLASSCI, les classes locales dans une école créée
     * depuis le LMS. La source est reçue par la MÉTHODE : Laravel garde le
     * contrôleur sur l'objet Route, et une source reçue par le constructeur
     * resterait celle de la première école servie.
     */
    public function classes(Request $request, InstitutionClassesReader $classes): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        try {
            $filters = $request->only(['filiere_id', 'niveau_id', 'annee_id']);

            return response()->json($classes->list($user, $filters));
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/classes/{id}/etudiants — Étudiants d'une classe.
     */
    public function etudiants(int $id, Request $request): JsonResponse
    {
        try {
            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            $anneeId = $request->input('annee_id');
            $anneeId = is_numeric($anneeId) ? (int) $anneeId : null;
            $data = $this->klassciService->getClasseEtudiants($klassciToken, $id, $anneeId);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/matieres — Matières.
     */
    public function matieres(Request $request): JsonResponse
    {
        try {
            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            $filters = $request->only(['filiere_id', 'niveau_id']);
            $data = $this->klassciService->getMatieres($klassciToken, $filters);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/matieres/{id} — Détails d'une matière.
     */
    public function matiereDetails(int $id, Request $request): JsonResponse
    {
        try {
            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            $data = $this->klassciService->getMatiereDetails($klassciToken, $id);
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/enseignants — fallback BDD si KLASSCI renvoie 0.
     *
     * La liste suit la grille des rôles (`TeacherDirectoryVisibility`), appliquée
     * après le cache de KLASSCI, qui est commun à l'établissement. Un lecteur qui
     * n'en verrait aucune ligne ne déclenche aucun appel.
     */
    public function enseignants(Request $request): JsonResponse
    {
        $lecteur = $request->user();
        if (! $lecteur instanceof User || ! $this->visibility->readsAnything($lecteur)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        try {
            $data = $this->klassciService->getEnseignants();

            if (empty($data['data'])) {
                Log::info('KLASSCI retourne 0 enseignants, utilisation BDD locale');

                return response()->json($this->localDirectory->forReader($lecteur));
            }

            return response()->json($this->visibility->envelopeFor($lecteur, $data));
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/filieres — Filières.
     */
    public function filieres(): JsonResponse
    {
        try {
            $data = $this->klassciService->getFilieres();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/niveaux-etudes — Niveaux d'études.
     */
    public function niveauxEtudes(): JsonResponse
    {
        try {
            $data = $this->klassciService->getNiveauxEtudes();
            return response()->json($data);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }

    /**
     * GET /api/proxy/test-connection — Diagnostic connexion KLASSCI.
     */
    public function testConnection(): JsonResponse
    {
        try {
            $isConnected = $this->klassciService->testConnection();

            return response()->json([
                'success' => $isConnected,
                'message' => $isConnected
                    ? 'Connexion à l\'API KLASSCI réussie'
                    : 'Impossible de se connecter à l\'API KLASSCI',
                'api_url' => config('services.klassci.url'),
            ]);
        } catch (\Exception $e) {
            return $this->proxyErrorResponse($e);
        }
    }
}
