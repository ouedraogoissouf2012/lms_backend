<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\LMS;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\StoreVisioConsentRequest;
use App\Services\Visio\Recording\VisioConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le consentement visio du porteur (#716 <-> front #333).
 *
 * Ce controleur est le chainon qui manquait : le frontend recueillait les trois
 * finalites et les rangeait dans `localStorage`, faute de destinataire cote
 * serveur. Le garde d'enregistrement attendait, lui, une ligne `consents` que
 * rien n'ecrivait — et refusait donc tout enregistrement en 422.
 */
final class LMSVisioConsentController extends AuthenticatedController
{
    public function __construct(private readonly VisioConsentService $consents) {}

    public function store(StoreVisioConsentRequest $request): JsonResponse
    {
        $result = $this->consents->record(
            $this->authenticatedUser($request),
            $request->choix(),
            $request->preuve(),
        );

        return $this->relayResponse($result);
    }

    public function show(Request $request): JsonResponse
    {
        return $this->relayResponse($this->consents->current($this->authenticatedUser($request)));
    }
}
