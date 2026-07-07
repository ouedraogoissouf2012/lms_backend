<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\DeleteUserRequest;
use App\Models\User;
use InvalidArgumentException;
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
        $data['password'] = bcrypt(self::validatedPassword($data));

        $user = User::create($data);
        $user->load('institution');

        return $this->successResponse($user, 'Utilisateur créé avec succès', 201);
    }

    /**
     * PUT /api/users/{user}
     * Mettre à jour un utilisateur
     */
    public function updateUser(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = bcrypt(self::validatedPassword($data));
        }

        $user->update($data);
        $user->load('institution');

        return $this->successResponse($user, 'Utilisateur mis à jour');
    }

    /**
     * DELETE /api/users/{user}
     * Supprimer un utilisateur
     */
    public function deleteUser(DeleteUserRequest $request, User $user): JsonResponse
    {
        $user->delete();

        return $this->successResponse(null, 'Utilisateur supprimé');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function validatedPassword(array $data): string
    {
        if (! isset($data['password']) || ! is_string($data['password'])) {
            throw new InvalidArgumentException('Validated password must be a string.');
        }

        return $data['password'];
    }
}
