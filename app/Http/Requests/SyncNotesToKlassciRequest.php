<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates note sync to KLASSCI (POST /api/evaluations/{id}/sync-notes-klassci).
 *
 * ## Purpose
 * Synchronize unsynced evaluation notes to KLASSCI.
 * Teacher/coordinator endpoint. No input validation (no body).
 *
 * ## Authorization Model
 * Teachers/coordinators only (verified via middleware).
 * Implicit: only teachers can sync their evaluations.
 *
 * ## 10-year Consideration
 * Marks submissions as synced_to_klassci = true after sync.
 * Only syncs status='soumis' submissions (not practice attempts).
 * If KLASSCI API for individual notes changes, update separately.
 */
final class SyncNotesToKlassciRequest extends FormRequest
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
