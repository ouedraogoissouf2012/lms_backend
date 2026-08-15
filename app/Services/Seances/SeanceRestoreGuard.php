<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;

/**
 * #542 — restaure une séance soft-deletée trouvée par un lookup `withTrashed()`,
 * en réinitialisant l'état « archivée » business (`is_active`/`archived_at`/
 * `archive_reason`) que `restore()` seul ne touche jamais (il ne remet à zéro
 * que `deleted_at`). Sans ce reset, une séance archivée AVANT sa suppression
 * (`StaleSeanceArchiver`) resterait invisible aux étudiants après une resync
 * pourtant réussie (aucune erreur, `deleted_at` bien à `null`).
 *
 * `restore()` est un no-op sûr sur une séance non trashed (aucun `UPDATE`
 * émis — `SoftDeletes::restore()` puis `save()` sur un modèle non dirty) —
 * le garde `trashed()` reste nécessaire malgré ça : il porte la décision de
 * réinitialiser l'état archivé, qui ne doit JAMAIS s'appliquer à une séance
 * active-mais-archivée trouvée hors d'un chemin de restauration réel.
 *
 * Champs positionnés AVANT `restore()` pour que son `save()` interne couvre
 * `deleted_at` + `is_active` + `archived_at` + `archive_reason` en 1 seul
 * `UPDATE` (pas deux appels `save()` séquentiels).
 *
 * Utilisé par `KlassciSeancesSyncService::upsertSeance()` (lookup scopé
 * `institution_id` explicite, contexte job) et
 * `VisioToggleService::restoreOrCreateVisio()` (lookup scopé par le scope
 * global tenant, contexte HTTP) — logique de restauration identique malgré
 * des lookups différents (cf. `.claude/specs/542-seance-soft-delete-upsert/design.md`).
 */
final class SeanceRestoreGuard
{
    public static function restoreIfTrashed(Seance $seance): void
    {
        if (! $seance->trashed()) {
            return;
        }

        $seance->is_active = true;
        $seance->archived_at = null;
        $seance->archive_reason = null;
        $seance->restore();
    }
}
