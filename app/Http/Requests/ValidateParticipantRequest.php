<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates participant access to an active visio (POST /api/lms/seances/{seanceId}/validate-participant).
 *
 * ## Purpose
 * Authorize and validate participant (user) access to an active visioconférence.
 * Extracted from inline Validator::make in LMSDataController::validateParticipant.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. Seance exists (visio must be enabled)
 * 3. Input: user_id exists in users table
 *
 * No KLASSCI token is required : validation reads local data only (#712). The
 * access decision lives in `ParticipantValidationService`
 * — institution filter, then a manager or the seance's own teacher
 * (`VisioActorAuthorization::canValidate()`).
 *
 * If ANY check fails → 401/403/404/422
 */
final class ValidateParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $institutionId = $this->user()?->institution_id;

        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('institution_id', $institutionId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'Le champ user_id est obligatoire.',
            'user_id.integer' => 'Le champ user_id doit être un entier.',
            'user_id.exists' => 'L\'utilisateur spécifié n\'existe pas.',
        ];
    }
}
