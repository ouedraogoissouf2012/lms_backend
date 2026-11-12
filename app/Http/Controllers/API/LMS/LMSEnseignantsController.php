<?php

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\KlassciUnavailableException;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Enseignant\InstitutionTeachersReader;
use App\Services\Klassci\TeacherDirectory\TeacherDirectoryVisibility;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LMS Enseignants — la liste des enseignants de l'établissement, pour
 * l'administration.
 *
 * Extracted from `LMSDataController` as part of the god-object refactor
 * (spec: `.claude/specs/lms-data-controller-split/`).
 *
 * Responsibility:
 *   - GET /api/lms/enseignants    → getEnseignantsFromKlassci()
 *
 * Même route, autre source (ADR-EI-01, lot 3b) : la source est reçue par la
 * MÉTHODE. Dans une école créée depuis KLASSCI, la liste enrichie de KLASSCI et
 * son cache local (`KlassciInstitutionTeachersReader`) ; dans une école créée
 * depuis le LMS, les comptes enseignants de l'école
 * (`LocalInstitutionTeachersReader`). Chaque source applique la grille
 * (`TeacherDirectoryVisibility`) à sa lecture ; le contrôleur garde le seul
 * court-circuit : un lecteur qui n'en verrait aucune ligne ne déclenche aucune
 * lecture.
 */
final class LMSEnseignantsController extends AuthenticatedController
{
    public function __construct(
        private readonly CaughtExceptionLog $journal,
        private readonly TeacherDirectoryVisibility $visibility,
    ) {}

    /**
     * GET /api/lms/enseignants
     *
     * Paramètres acceptés:
     *   - with_details (boolean): activer le format enrichi (classes/matières/stats)
     */
    public function getEnseignantsFromKlassci(Request $request, InstitutionTeachersReader $enseignants): JsonResponse
    {
        try {
            $lecteur = $this->authenticatedUser($request);
            if (! $this->visibility->readsAnything($lecteur)) {
                return $this->successResponse([], '', 200, ['total' => 0]);
            }

            $liste = $enseignants->listeEnrichie($lecteur, $request->boolean('with_details', false));

            if (! $liste['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $liste['message'] ?? 'Erreur KLASSCI',
                    'data' => [],
                ], 500);
            }

            return $this->successResponse($liste['data'], '', 200, $liste['meta']);

        } catch (KlassciUnavailableException $e) {
            // Panne KLASSCI : 503 retryable, jamais le 500 generique ci-dessous.
            // Un 500 est definitif pour le client ; il transformerait une coupure
            // de quelques minutes en echec permanent. Reponse canonique unique,
            // partagee avec le handler global et le trait proxy.
            return KlassciUnavailableException::jsonResponse();
        } catch (\Exception $e) {
            // §1.2 — Détail technique loggé server-side, message générique au client.
            $this->journal->error('[LMS Enseignants KLASSCI] Erreur', $e);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des enseignants.',
                'data'    => [],
            ], 500);
        }
    }
}
