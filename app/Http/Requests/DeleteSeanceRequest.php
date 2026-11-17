<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuthorizesSeanceDeletion;
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
    use AuthorizesSeanceDeletion;

    /**
     * La séance dans l'espace de l'école (#933) : la même source que le service
     * qui supprime, résolue à chaque requête.
     */
    public function authorize(SeanceDesignee $designee, VisioActorAuthorization $auteurs): bool
    {
        return $this->autoriseLaSuppression($designee, $auteurs);
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
