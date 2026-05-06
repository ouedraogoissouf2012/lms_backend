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

        return response()->json([
            'success' => true,
            'message' => 'Service connecté avec succès',
            'data' => [
                'service' => $data['service'],
                'status' => 'connected',
                'api_key' => substr($data['api_key'], 0, 10) . '****',
            ],
        ], 201);
    }

    /**
     * POST /api/integrations/authorize
     * Autoriser un service via OAuth
     */
    public function authorize(AuthorizeServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'success' => true,
            'message' => 'Service autorisé avec succès',
            'data' => [
                'service' => $data['service'],
                'status' => 'authorized',
            ],
        ]);
    }

    /**
     * POST /api/integrations/test
     * Tester la connexion d'un service
     */
    public function testConnection(TestServiceConnectionRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'success' => true,
            'message' => 'Connexion valide',
            'data' => [
                'service' => $data['service'],
                'status' => 'connected',
            ],
        ]);
    }

    /**
     * POST /api/integrations/disconnect
     * Déconnecter un service
     */
    public function disconnect(DisconnectServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'success' => true,
            'message' => 'Service déconnecté',
            'data' => [
                'service' => $data['service'],
            ],
        ]);
    }
}
