<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\DeleteUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * AdminController — User management and administration
 *
 * Purpose: Handle admin operations (user CRUD, settings, roles)
 * Authorization Model: coordinateur (create/read/update) + superAdmin (create/read/update/delete)
 * Consideration: All operations are multi-tenant via BelongsToInstitution
 */
class AdminController extends Controller
{
    /**
     * POST /api/users
     * Créer un nouvel utilisateur (admin only)
     */
    public function createUser(CreateUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['password'] = bcrypt($data['password']);

        $user = User::create($data);
        $user->load('institution');

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur créé avec succès',
            'data' => $user,
        ], 201);
    }

    /**
     * PUT /api/users/{user}
     * Mettre à jour un utilisateur
     */
    public function updateUser(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        }

        $user->update($data);
        $user->load('institution');

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur mis à jour',
            'data' => $user,
        ]);
    }

    /**
     * DELETE /api/users/{user}
     * Supprimer un utilisateur
     */
    public function deleteUser(DeleteUserRequest $request, User $user): JsonResponse
    {
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur supprimé',
        ]);
    }
}
