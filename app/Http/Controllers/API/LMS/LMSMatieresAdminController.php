<?php

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\KlassciUnavailableException;
use App\Http\Controllers\API\Proxy\Concerns\ResolvesPersonalKlassciToken;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Matiere\AdminMatieresQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * LMSMatieresAdminController — liste des matières pour l'administration.
 *
 * Orchestrateur fin (§5) : authentification, garde de rôle, délégation,
 * sérialisation. La construction du payload vit dans
 * {@see AdminMatieresQueryService}, qui porte aussi la raison du geste
 * (suppression d'un N+1 de 453 appels KLASSCI).
 */
final class LMSMatieresAdminController extends AuthenticatedController
{
    use ResolvesPersonalKlassciToken;

    public function __construct(
        private readonly AdminMatieresQueryService $matieresQuery,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /api/admin/matieres
     * Liste toutes les matières avec leurs combinaisons (admin/coordinateur).
     */
    public function adminMatieresList(Request $request): JsonResponse
    {
        try {
            $user = $this->authenticatedUser($request);
            $klassciToken = $this->personalKlassciToken($request);
            if ($klassciToken === null) {
                return $this->missingKlassciTokenResponse();
            }

            // Defense in depth: la route middleware `role:admin,coordinateur` filtre
            // déjà, mais on garde le check controller comme garde supplémentaire.
            // Note: `superAdmin` accepté car role intra-tenant admin (cf. EnsureRole.php).
            if (! $user->isManager()) {
                return $this->errorResponse('Accès non autorisé. Réservé aux administrateurs et coordinateurs.', 403);
            }

            $result = $this->matieresQuery->listForAdmin($klassciToken);
            $count = count($result['matieres']);

            // Une ligne par requête. Avant, l'enrichissement journalisait CHAQUE
            // matière : 452 écritures par appel, qui pesaient sur la latence
            // autant que sur la taille du fichier de log.
            $this->logger->info('Liste matières admin', [
                'user_id' => $user->id,
                'count' => $count,
            ]);

            if ($count === 0) {
                return $this->successResponse($result, 'Aucune matière trouvée');
            }

            return $this->successResponse($result, $count . ' matière(s) récupérée(s)');
        } catch (KlassciUnavailableException) {
            // Panne KLASSCI : 503 retryable, jamais le 500 generique ci-dessous.
            // Un 500 est definitif pour le client ; il transformerait une coupure
            // de quelques minutes en echec permanent.
            return KlassciUnavailableException::jsonResponse();
        } catch (\Exception $e) {
            $this->logger->error('Erreur liste matières admin', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des matières',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }
}
