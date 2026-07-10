<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates attendance sync request (POST /api/lms/attendances/from-video-session).
 *
 * ## Purpose
 * Authorize and validate the request to synchronize attendances from a video session.
 * Extracted from inline Validator::make in LMSDataController::syncAttendancesFromVideoSession.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User has KLASSCI token (for subsequent processing)
 * 3. Input validation:
 *    - seance_cours_id (required, integer) - ID of the course seance
 *    - date (required, date format)
 *    - participants[] array with:
 *      - etudiant_id (required, integer)
 *      - statut (required, enum: present/absent/retard)
 *      - joined_at, left_at (nullable, date)
 *      - duration_minutes (nullable, integer >= 0)
 *
 * If ANY check fails → 401/422
 */
final class SyncAttendancesRequest extends FormRequest
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
            'date' => 'required|date',
            'participants' => 'required|array|min:1',
            'participants.*.etudiant_id' => 'required|integer',
            'participants.*.statut' => 'required|in:present,absent,retard',
            'participants.*.joined_at' => 'nullable|date',
            'participants.*.left_at' => 'nullable|date',
            'participants.*.duration_minutes' => 'nullable|integer|min:0',
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
            'date.required' => 'Le champ date est obligatoire.',
            'date.date' => 'Le champ date doit être une date valide.',
            'participants.required' => 'Au moins un participant est requis.',
            'participants.array' => 'Le champ participants doit être un tableau.',
            'participants.min' => 'Au moins un participant est requis.',
            'participants.*.etudiant_id.required' => 'L\'ID étudiant est obligatoire pour chaque participant.',
            'participants.*.etudiant_id.integer' => 'L\'ID étudiant doit être un entier.',
            'participants.*.statut.required' => 'Le statut est obligatoire pour chaque participant.',
            'participants.*.statut.in' => 'Le statut doit être l\'un de: present, absent, retard.',
            'participants.*.joined_at.date' => 'La date de connexion doit être valide.',
            'participants.*.left_at.date' => 'La date de déconnexion doit être valide.',
            'participants.*.duration_minutes.integer' => 'La durée doit être un entier.',
            'participants.*.duration_minutes.min' => 'La durée doit être supérieure ou égale à 0.',
        ];
    }
}
