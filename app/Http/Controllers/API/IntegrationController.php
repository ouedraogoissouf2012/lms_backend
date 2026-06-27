<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectServiceRequest;
use App\Http\Requests\AuthorizeServiceRequest;
use App\Http\Requests\TestServiceConnectionRequest;
use App\Http\Requests\DisconnectServiceRequest;
use Illuminate\Http\JsonResponse;

class IntegrationController extends Controller
{
    /**
     * POST /api/integrations/connect
     * Connecter un service tiers
     */
    public function connect(ConnectServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse([
            'service' => $data['service'],
            'status' => 'connected',
            'api_key' => substr($data['api_key'], 0, 10) . '****',
        ], 'Service connecté avec succès', 201);
    }

    /**
     * POST /api/integrations/authorize
     * Autoriser un service via OAuth
     */
    public function authorize(AuthorizeServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse([
            'service' => $data['service'],
            'status' => 'authorized',
        ], 'Service autorisé avec succès');
    }

    /**
     * POST /api/integrations/test
     * Tester la connexion d'un service
     */
    public function testConnection(TestServiceConnectionRequest $request): JsonResponse
    {
        $data = $request->validated();

        // NB: `$data['service']` n'est pas validé (TestServiceConnectionRequest::rules()
        // est vide) → accès clé absente → 500 (bug latent pré-existant, cf. test
        // de caractérisation). Comportement PRÉSERVÉ tel quel (axe #1 iso-sortie).
        return $this->successResponse([
            'service' => $data['service'],
            'status' => 'connected',
        ], 'Connexion valide');
    }

    /**
     * POST /api/integrations/disconnect
     * Déconnecter un service
     */
    public function disconnect(DisconnectServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        // NB: `$data['service']` n'est pas validé (DisconnectServiceRequest::rules()
        // est vide) → accès clé absente → 500 (bug latent pré-existant, cf. test
        // de caractérisation). Comportement PRÉSERVÉ tel quel (axe #1 iso-sortie).
        return $this->successResponse([
            'service' => $data['service'],
        ], 'Service déconnecté');
    }
}
