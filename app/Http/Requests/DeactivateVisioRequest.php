<?php

namespace App\Http\Requests;

use App\Models\Seance;
use App\Models\User;
use App\Services\Visio\VisioActorAuthorization;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates deactivate visio request (POST /api/lms/seances/{seanceId}/deactivate-visio).
 *
 * ## Purpose
 * Authorize disabling visioconférence for a seance.
 * No input validation required — only authorization checks.
 * Extracted from inline role/ownership checks in LMSDataController::deactivateVisio.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is enseignant only (enforced by route middleware role:enseignant)
 * 3. User must own the seance (klassci_enseignant_id matches user.klassci_id)
 * 4. User has KLASSCI token (implicit, already checked by route)
 *
 * If ANY check fails → 401/403/404
 *
 * State checks (visio_enabled) remain in controller — business logic.
 */
final class DeactivateVisioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = $this->user();
        if (! $user instanceof User || ! $user->isTeacher()) {
            return false;
        }

        $seance = $this->resolveSeance();
        if (! $seance instanceof Seance) {
            return false;
        }

        return app(VisioActorAuthorization::class)->teacherOwns($seance, $user);
    }

    private function resolveSeance(): ?Seance
    {
        $seanceId = $this->route('seanceId');
        $byId = Seance::query()->find($seanceId);
        if ($byId instanceof Seance) {
            return $byId;
        }

        $byKlassci = Seance::query()->where('klassci_seance_id', $seanceId)->first();

        return $byKlassci instanceof Seance ? $byKlassci : null;
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
