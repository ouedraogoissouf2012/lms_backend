<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Exceptions\UnserializablePayloadException;
use App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors;
use App\Http\Controllers\AuthenticatedController;
use App\Services\Classe\ClasseDetailsQueryService;
use App\Services\Classe\ClasseDetailsReader;
use App\Services\Classe\ClasseEtudiantsQueryService;
use App\Services\Classe\ClasseEtudiantsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * LMS Classes — détails et étudiants (thin controller).
 *
 * Extracted from `LMSDataController` (god-object split — spec
 * `.claude/specs/lms-data-controller-split/`) puis splitté en services
 * SRP lors de `split-20/lms-classes` afin de respecter §5 (controllers
 * ≤200 lignes) et §1.1 (services ≤300 lignes).
 *
 * Responsibilities :
 *   - GET /api/lms/classes/{classeId}              → classeDetails()
 *   - GET /api/lms/classes/{classeId}/etudiants    → classeEtudiants()
 *
 * Chaque lecture passe par un port, dont la source est choisie au point de
 * liaison selon le type d'école (ADR-EI-01) :
 *   - {@see ClasseDetailsReader}  — endpoint « détails » ; source KLASSCI
 *     {@see ClasseDetailsQueryService}.
 *   - {@see ClasseEtudiantsReader} — endpoint « roster étudiants » ; source
 *     KLASSCI {@see ClasseEtudiantsQueryService}.
 * La source est reçue par la MÉTHODE : Laravel garde le contrôleur sur l'objet
 * Route, et une source reçue par le constructeur resterait celle de la première
 * école servie.
 *
 * Le controller se borne à : auth + délégation + sérialisation JSON +
 * filet de sécurité 500 pour les exceptions imprévues.
 *
 * Une panne interne n'est jamais écrite à la main : elle est RAPPORTÉE
 * (`report()`), une fois, par la règle commune (#948), qui n'écrit ni le
 * message d'une `QueryException` ni les valeurs de sa requête. Une panne de base
 * est une `RuntimeException` : elle passe par la branche 500 du trait.
 */
final class LMSClassesController extends AuthenticatedController
{
    // La fiche et le roster : un refus KLASSCI garde son statut et son motif,
    // une panne devient un 503 + `Retry-After` (#270). Jamais un 500 générique.
    // Toute autre `RuntimeException`, panne de base comprise, est rapportée et
    // rendue en 500 générique par la branche du trait.
    use RendersKlassciBackedErrors;

    /**
     * GET /api/lms/classes/{id}
     * Retourne les détails complets d'une classe (infos, étudiants actifs,
     * matières, emploi du temps de la semaine, évaluations programmées, stats).
     */
    public function classeDetails(int $classeId, Request $request, ClasseDetailsReader $details): JsonResponse
    {
        // Hors du `try` : un compte absent est un 401 du gestionnaire global,
        // jamais une panne du filet (que le rapporteur tairait).
        $user = $this->authenticatedUser($request);

        try {
            $result = $details->getDetailsForUser($classeId, $user);

            return $this->relayResponse($result);
        } catch (UnserializablePayloadException $e) {
            // #693 — la garde de serialisabilite ne doit PAS etre avalee ici.
            // `UnserializablePayloadException` etend `LogicException`, donc
            // `Throwable` : sans ce catch, elle tombait dans le fourre-tout
            // ci-dessous, etait journalisee sous une cause FAUSSE, et rendue en
            // 500 generique. Son propre docblock dit « elle ne doit pas etre
            // attrapee » — c'est une erreur de programmation, pas une panne
            // metier. On la relance pour le handler global.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        } catch (Throwable $e) {
            return $this->panneInterne($e, 'Erreur lors de la récupération des détails de la classe');
        }
    }

    /**
     * GET /api/lms/classes/{id}/etudiants
     * Retourne la liste des étudiants d'une classe (filtre permissif —
     * cf. {@see ClasseEtudiantsQueryService}).
     */
    public function classeEtudiants(int $classeId, Request $request, ClasseEtudiantsReader $etudiants): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        try {
            $result = $etudiants->getEtudiants($classeId, $user);

            return $this->relayResponse($result);
        } catch (UnserializablePayloadException $e) {
            // #693 — même règle que `classeDetails()` : relancée, jamais avalée.
            throw $e;
        } catch (RuntimeException $e) {
            return $this->renderKlassciFailure($e);
        } catch (Throwable $e) {
            return $this->panneInterne($e, 'Erreur lors de la récupération des étudiants');
        }
    }

    /**
     * Le filet des erreurs qui ne sont pas des `RuntimeException` : rapportée une
     * fois (#948), sans message ni trace écrits à la main ; le corps garde sa
     * forme (`error` présent : erreur rattrapée par le contrôleur).
     */
    private function panneInterne(Throwable $e, string $message): JsonResponse
    {
        report($e);

        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => 'Une erreur est survenue.',
        ], 500);
    }
}
