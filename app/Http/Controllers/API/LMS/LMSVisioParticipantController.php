<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\HeartbeatVisioRequest;
use App\Http\Requests\JoinVisioRequest;
use App\Http\Requests\LeaveVisioRequest;
use App\Services\Visio\ActiveVisioParticipationResolver;
use App\Services\Visio\VisioHeartbeatService;
use App\Services\Visio\VisioParticipantSessionService;
use App\Services\Visio\VisioParticipantsListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LMSVisioParticipantController — thin controller (§5 ≤200l).
 *
 * Découpe issue du split du god-controller (549 lignes → controller
 * thin + 4 services SRP dans `App\Services\Visio\`). Les 4 endpoints
 * gèrent le cycle de vie d'un participant à une visio (join, leave,
 * heartbeat, listing) et restent groupés ici pour la cohésion du
 * domaine — chaque méthode délègue à son service dédié.
 *
 * IMPORTANT : la classe et le nom de méthode `getVisioParticipants`
 * sont fixés par `tests/Feature/LMS/Visio/VisioRoutingTest.php`
 * (REQ-4 du spec) ; ne pas renommer sans mettre à jour ce test.
 *
 * @see PRODUCTION_STANDARDS.md §5  — Controllers ≤200 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class LMSVisioParticipantController extends AuthenticatedController
{
    public function __construct(
        private readonly VisioParticipantSessionService $sessionService,
        private readonly VisioHeartbeatService $heartbeatService,
        private readonly VisioParticipantsListService $listService,
        private readonly ActiveVisioParticipationResolver $participationResolver,
    ) {}

    /**
     * GET /api/lms/visio/active
     *
     * La participation en cours de l'appelant, ou `null`. Sert à remonter la
     * salle embarquée après un rechargement de page (#673).
     *
     * Aucun jeton n'est délivré ici : le front rappelle `join`, qui revérifie
     * l'autorisation et taille la clé. Émettre une clé depuis deux endroits
     * créerait un second endroit où se tromper.
     */
    public function activeParticipation(Request $request): JsonResponse
    {
        $participation = $this->participationResolver->forUser($this->authenticatedUser($request));

        return response()->json(['success' => true, 'data' => $participation], 200);
    }

    /**
     * POST /api/lms/seances/{seanceId}/join
     * Logger qu'un étudiant rejoint la visio.
     */
    public function joinVisio(int $seanceId, JoinVisioRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->sessionService->join($seanceId, $user, $request);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/lms/seances/{seanceId}/leave
     * Enregistre la déconnexion d'un participant.
     */
    public function leaveVisio(int $seanceId, LeaveVisioRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->sessionService->leave($seanceId, $user);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/seances/{seanceId}/heartbeat
     * Mettre à jour le heartbeat d'un participant (ping d'activité).
     */
    public function heartbeatVisio(int $seanceId, HeartbeatVisioRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->heartbeatService->heartbeat($seanceId, $user);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * GET /api/lms/seances/{seanceId}/visio-participants
     * Récupérer la liste unifiée des participants (présents + absents).
     */
    public function getVisioParticipants(int $seanceId, Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->listService->list($seanceId, $user);

        return response()->json($result['payload'], $result['status']);
    }
}
