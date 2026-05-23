<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation sync to KLASSCI (POST /api/evaluations/{id}/sync-klassci).
 *
 * ## Purpose
 * Synchronize evaluation grades to KLASSCI system.
 * Teacher/coordinator endpoint. No input validation (no body).
 *
 * ## Authorization Model
 * Teachers/coordinators and admins (verified via role:enseignant,coordinateur,superAdmin middleware).
 * Implicit check: only teachers who created evaluation can sync.
 *
 * ## 10-year Consideration
 * One-way sync (LMS → KLASSCI). No pull-back.
 * Submissions marked as synced_to_klassci = true (audit trail).
 * If KLASSCI API changes, update SyncService separately.
 */
final class SyncToKlassciRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        return $user !== null && ($user->isTeacher() || $user->isCoordinator() || $user->isAdmin());
    }

    public function rules(): array
    {
        return [];
    }
}
