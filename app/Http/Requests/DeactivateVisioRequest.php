<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates deactivate visio request (POST /api/lms/seances/{seanceId}/deactivate-visio).
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is enseignant or coordinateur (route middleware, defense in depth here)
 *
 * The visio management rule (`VisioActorAuthorization::canManage`: the owning
 * teacher or the establishment's management staff) is applied by
 * `VisioDeactivation` on the resolved seance, like activate, start and end :
 * 404 for an unknown seance, 403 `actor_not_authorized` otherwise.
 */
final class DeactivateVisioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && ($user->isTeacher() || $user->isCoordinator());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // No input validation for POST with no body
        return [];
    }
}
