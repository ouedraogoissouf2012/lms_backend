<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetConfigurationRequest;
use App\Http\Requests\UpdateConfigurationRequest;
use App\Http\Requests\DeleteConfigurationRequest;
use Illuminate\Http\JsonResponse;

class ConfigurationController extends Controller
{
    /**
     * GET /api/configuration
     * Récupérer la configuration du système
     */
    public function get(GetConfigurationRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'success' => true,
            'data' => [
                'key' => $data['key'] ?? null,
                'category' => $data['category'] ?? null,
            ],
        ]);
    }

    /**
     * PUT /api/configuration
     * Mettre à jour la configuration du système
     */
    public function update(UpdateConfigurationRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'success' => true,
            'message' => 'Configuration mise à jour avec succès',
            'data' => [
                'key' => $data['key'],
                'value' => $data['value'],
                'category' => $data['category'],
            ],
        ]);
    }

    /**
     * DELETE /api/configuration
     * Supprimer une configuration
     */
    public function delete(DeleteConfigurationRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json([
            'success' => true,
            'message' => 'Configuration supprimée',
            'data' => [
                'key' => $data['key'],
            ],
        ]);
    }
}
