<?php

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\KlassciUnavailableException;
use App\Exceptions\MissingKlassciTokenException;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Matiere\AdminMatieresQueryService;
use App\Services\Matiere\InstitutionMatieresReader;
use App\Support\Logging\CaughtExceptionLog;
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
 *
 * Même route, autre source (ADR-EI-01, lot 3c) : la source est reçue par la
 * MÉTHODE — Laravel garde le contrôleur sur l'objet Route, et une source reçue
 * par le constructeur resterait celle de la première école servie. Le jeton
 * KLASSCI personnel est exigé par la source KLASSCI : vérifié ici, il
 * refuserait l'école créée depuis le LMS.
 */
final class LMSMatieresAdminController extends AuthenticatedController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * GET /api/admin/matieres
     * Liste toutes les matières avec leurs combinaisons (admin/coordinateur).
     */
    public function adminMatieresList(Request $request, InstitutionMatieresReader $matieres): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        // Defense in depth: la route middleware `role:admin,coordinateur` filtre
        // déjà, mais on garde le check controller comme garde supplémentaire.
        // Note: `superAdmin` accepté car role intra-tenant admin (cf. EnsureRole.php).
        // Il passe AVANT la lecture : la source locale ne lit rien pour un
        // appelant que la gestion n'admet pas.
        if (! $user->isManager()) {
            return $this->errorResponse('Accès non autorisé. Réservé aux administrateurs et coordinateurs.', 403);
        }

        try {
            $result = $matieres->listForAdmin($user);
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
        } catch (MissingKlassciTokenException) {
            return MissingKlassciTokenException::jsonResponse();
        } catch (KlassciUnavailableException) {
            // Panne KLASSCI : 503 retryable, jamais le 500 generique ci-dessous.
            // Un 500 est definitif pour le client ; il transformerait une coupure
            // de quelques minutes en echec permanent.
            return KlassciUnavailableException::jsonResponse();
        } catch (\Exception $e) {
            $this->journal->error('Erreur liste matières admin', $e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des matières',
                'error' => 'Une erreur est survenue.',
            ], 500);
        }
    }
}
