<?php

namespace App\Http\Requests;

use App\Models\Seance;
use App\Models\User;
use App\Services\Seances\SeanceDesignee;
use App\Services\Visio\VisioActorAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates delete seance request (DELETE /api/lms/seances/{seanceId}).
 *
 * ## Purpose
 * Authorize deletion of a seance. No input validation required.
 * Extracted from inline role/ownership checks in LMSDataController::deleteSeance.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is enseignant/coordinateur/superAdmin (route middleware enforces base roles)
 * 3. For enseignants only: seance must belong to user (klassci_enseignant_id matches)
 * 4. For coordinateurs/admins: can delete any seance
 *
 * State checks (visio_active) remain in controller — business logic, not validation.
 *
 * If ANY check fails → 403/404
 */
final class DeleteSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = $this->user();
        if (! $user instanceof User) {
            return false;
        }

        if (! ($user->isTeacher() || $user->isCoordinator() || $user->isAdmin())) {
            return false;
        }

        $seance = $this->resolveSeance($user);
        if (! $seance instanceof Seance) {
            return false;
        }

        if ($user->isTeacher() && ! app(VisioActorAuthorization::class)->teacherOwns($seance, $user)) {
            return false;
        }

        return true;
    }

    /**
     * La séance dans l'espace de l'école (#933) : la même source que le service
     * qui supprime, résolue à chaque requête.
     */
    private function resolveSeance(User $user): ?Seance
    {
        $seanceId = $this->route('seanceId');

        return is_numeric($seanceId) ? app(SeanceDesignee::class)->pour((int) $seanceId, $user) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // No input validation for DELETE
        return [];
    }
}
