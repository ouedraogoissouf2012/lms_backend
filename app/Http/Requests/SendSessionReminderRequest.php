<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates send session reminder request (POST /api/lms/notifications/send-session-reminder).
 *
 * ## Purpose
 * Authorize and validate the request to send session reminders via multiple channels.
 * Extracted from inline Validator::make in LMSDataController::sendSessionReminder.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User has KLASSCI token (for subsequent processing)
 * 3. Input validation:
 *    - seance_cours_id (required, integer) - ID of the course seance
 *    - channels[] (required, array with enum values: whatsapp, email, sms, app)
 *    - minutes_before (nullable, integer 0-1440)
 *
 * If ANY check fails → 401/422
 */
final class SendSessionReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Check 2: User must have klassci token
        if (!$user->klassci_token) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'seance_cours_id' => 'required|integer|min:1',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:whatsapp,email,sms,app',
            'minutes_before' => 'nullable|integer|min:0|max:1440',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'seance_cours_id.required' => 'Le champ seance_cours_id est obligatoire.',
            'seance_cours_id.integer' => 'Le champ seance_cours_id doit être un entier.',
            'seance_cours_id.min' => 'Le champ seance_cours_id doit être supérieur à 0.',
            'channels.required' => 'Au moins un canal est requis.',
            'channels.array' => 'Le champ channels doit être un tableau.',
            'channels.min' => 'Au moins un canal est requis.',
            'channels.*.in' => 'Les canaux doivent être parmi: whatsapp, email, sms, app.',
            'minutes_before.integer' => 'Le champ minutes_before doit être un entier.',
            'minutes_before.min' => 'Le champ minutes_before doit être supérieur ou égal à 0.',
            'minutes_before.max' => 'Le champ minutes_before doit être inférieur ou égal à 1440.',
        ];
    }
}
