<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PERF-05 — nettoyer les emails `etudiant{id}@temp.local` historiques.
 *
 * ## Contexte
 *
 * `ClasseSyncService::syncClasseStudents()` fabriquait `etudiant{klassci_id}@temp.local`
 * comme placeholder quand KLASSCI ne retournait pas d'email. Conséquences :
 *
 *   • Pollution table users avec des emails synthétiques inutilisables
 *   • Ces users ne pouvaient JAMAIS se connecter (pas d'email réel)
 *   • Fuite d'info : l'email construit révélait l'existence d'un user pour klassci_id N
 *   • Risque de match faux positif si un user reverse-engineerait le pattern
 *
 * Le code source a été corrigé dans la PR PERF-05 (set email=null à la place).
 *
 * ## Cette migration
 *
 * NULL-out tous les emails matching le pattern `etudiant{N}@temp.local` pour ne
 * pas laisser de résidu de la fuite d'info historique. La colonne email a été
 * rendue nullable par `2025_10_19_202208_make_password_nullable_in_users_table`.
 *
 * Idempotente : si aucun email ne matche, rien ne se passe.
 *
 * Non destructive : on ne supprime PAS les users — on NULL-out leur email pour
 * que la contrainte unique `(email, institution_id)` ne se déclenche pas sur
 * des collisions futures avec d'autres users sans email. Le user reste joignable
 * via `klassci_id` pour les flows de re-sync KLASSCI.
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::table('users')
            ->where('email', 'LIKE', 'etudiant%@temp.local')
            ->update(['email' => null]);

        Log::info('PERF-05 cleanup: NULL-out emails @temp.local', [
            'affected_rows' => $affected,
        ]);
    }

    /**
     * Down : pas de rollback — les emails fake ne doivent jamais réapparaître.
     * Si un rollback de cette migration est nécessaire, c'est qu'il y a un
     * souci plus grave qui mérite une intervention manuelle ciblée.
     */
    public function down(): void
    {
        // No-op intentionnel.
    }
};
