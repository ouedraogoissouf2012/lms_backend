<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\DeleteUserRequest;
use App\Http\Requests\ListUsersRequest;
use App\Http\Presenters\UserListPresenter;
use App\Models\User;
use App\Services\User\UserDeletionService;
use App\Services\User\UserListService;
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
     * Supprimer un utilisateur — suppression LOGIQUE (#566) : le dossier
     * académique est préservé et l'accès du compte immédiatement révoqué.
     * La logique (atomicité, révocation, audit) vit dans le service (§5).
     */
    public function deleteUser(DeleteUserRequest $request, User $user, UserDeletionService $deletion): JsonResponse
    {
        $deletion->softDelete($user);

        return $this->successResponse(null, 'Utilisateur supprimé');
    }

    /**
     * GET /api/admin/users
     * Liste paginee des COMPTES LMS du tenant (coordinateurs et admins inclus).
     *
     * L'ecran d'annuaire ne passait que par le proxy KLASSCI, qui ne connait ni
     * les coordinateurs ni les admins : ces comptes etaient invisibles. Ici, on
     * lit la base LMS, seule source des comptes qui peuvent se connecter.
     *
     * Le refus du supradmin plateforme est porte par ListUsersRequest::authorize().
     */
    public function listUsers(
        ListUsersRequest $request,
        UserListService $users,
        UserListPresenter $presenter,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        return $this->successResponse(
            $presenter->paginated($users->paginate($actor, $request->validated())),
            '',
            200,
            ['counts' => $users->counts($actor)],
        );
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
