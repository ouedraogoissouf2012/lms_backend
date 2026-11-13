<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy;

use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use App\Http\Controllers\API\Proxy\Concerns\ResolvesPersonalKlassciToken;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Catalogue\InstitutionStructureReader;
use App\Services\Classe\InstitutionClassesReader;
use App\Services\Enseignant\InstitutionTeachersReader;
use App\Services\Klassci\TeacherDirectory\TeacherDirectoryVisibility;
use App\Services\KlassciProxyService;
use App\Services\Matiere\InstitutionMatieresReader;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    ) {
    }

    /**
     * GET /api/proxy/structure — Structure organisationnelle (filières, niveaux).
     *
     * Même route, autre source (ADR-EI-01, lot 3c) : KLASSCI relayé dans une
     * école créée depuis KLASSCI, des listes vides dans une école créée depuis
     * le LMS. La source est reçue par la MÉTHODE, comme pour `classes()`.
     */
    public function structure(InstitutionStructureReader $structure): JsonResponse
    {
        try {
            return response()->json($structure->structure());
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
     * GET /api/proxy/matieres — les matières de l'établissement.
     *
     * Même route, autre source (ADR-EI-01, lot 3c). Le jeton KLASSCI personnel
     * est exigé par la source KLASSCI, plus ici : vérifié avant le choix de
     * source, il refuserait l'école créée depuis le LMS. Un jeton absent y lève
     * `MissingKlassciTokenException`, rendue en 401 `klassci_token_missing`
     * comme avant.
     */
    public function matieres(Request $request, InstitutionMatieresReader $matieres): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        try {
            $filters = $request->only(['filiere_id', 'niveau_id']);

            return response()->json($matieres->list($user, $filters));
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
     * GET /api/proxy/enseignants — l'annuaire des enseignants.
     *
     * Même route, autre source (ADR-EI-01, lot 3b) : KLASSCI, avec son repli
     * local quand il n'en rend aucun, ou les comptes de l'école créée depuis le
     * LMS. Chaque source applique la grille des rôles (`TeacherDirectoryVisibility`)
     * à sa lecture. Un lecteur qui n'en verrait aucune ligne ne déclenche aucune
     * lecture.
     */
    public function enseignants(Request $request, InstitutionTeachersReader $enseignants): JsonResponse
    {
        $lecteur = $request->user();
        if (! $lecteur instanceof User || ! $this->visibility->readsAnything($lecteur)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        try {
            return response()->json($enseignants->annuaire($lecteur));
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
