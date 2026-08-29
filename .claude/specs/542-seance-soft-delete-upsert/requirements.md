# Requirements — #542 [P2] Séance soft-deleted bloque la recréation/sync

## Contexte

`Seance` (`app/Models/Seance.php:21`) utilise `SoftDeletes`. La table porte un
index unique **composite** `(klassci_seance_id, institution_id)`
(`seances_klassci_institution_unique`, migration `2026_07_20_000001`) — un
index SQL standard, **pas** filtré sur `deleted_at IS NULL`. Une ligne
soft-deleted occupe donc toujours sa place dans l'index unique.

Trois call sites font un lookup-puis-create SANS `withTrashed()` :

1. `KlassciSeancesSyncService::upsertSeance()` (`app/Services/Seances/Sync/KlassciSeancesSyncService.php:224-227`)
   — lookup explicite `Seance::withoutGlobalScope('institution')->where('institution_id', ...)->where('klassci_seance_id', ...)->first()`.
2. `VisioToggleService::toggle()` (`app/Services/Seances/Mutations/VisioToggleService.php:75-89`)
   — `Seance::updateOrCreate(['klassci_seance_id' => $seanceId], [...])`.
3. `VisioActivationService::activate:58` (`app/Services/Visio/Lifecycle/VisioActivationService.php`)
   — même pattern `Seance::updateOrCreate(...)`.

## R0 — Périmètre de fichiers (contrainte non négociable)

Le périmètre assigné à cette fenêtre est `app/Jobs/SyncKlassciSeances.php`,
`app/Jobs/CleanObsoleteSeances.php`, `app/Services/Seances/*`, les
repositories/sync de séances, et leurs tests. `VisioActivationService.php`
vit dans `app/Services/Visio/Lifecycle/` — **hors périmètre**.

**Décision** : corriger les 2 call sites in-domain (#1, #2). Le call site #3
(`VisioActivationService::activate:58`) porte EXACTEMENT le même bug (vérifié
par lecture directe du fichier : `Seance::updateOrCreate(['klassci_seance_id' => $seanceId], [...])`,
identique au call site #2) mais n'est PAS touché ici — signalé à
l'orchestrateur comme recommandation de suivi (même fichier, même
correctif, à traiter par la fenêtre propriétaire de `app/Services/Visio/*`).
Ne pas le corriger laisse un vecteur du bug ouvert, mais c'est le
compromis correct : modifier un fichier hors périmètre sans coordination
risque une collision avec une autre fenêtre parallèle (cf. incident #548 de
cette même session).

## R1 — Le lookup doit inclure les lignes soft-deleted

Chaque call site corrigé doit chercher la séance existante AVEC
`withTrashed()` (pas seulement les lignes actives), pour détecter une
collision potentielle sur `(klassci_seance_id, institution_id)` AVANT
d'écrire.

**Scénario reproductible (RED)** : créer une séance, la soft-delete
(`$seance->delete()`), puis re-synchroniser/re-basculer la MÊME
`klassci_seance_id` (même institution) → doit RÉUSSIR (pas de
`QueryException` sur violation d'unique), la séance doit être restaurée et à
jour, pas dupliquée.

## R2 — Restauration explicite, pas une resurrection accidentelle des données obsolètes

Si le lookup `withTrashed()` trouve une ligne trashed, elle doit être
restaurée (`restore()`, remet `deleted_at` à `null`) PUIS mise à jour avec les
données fraîches de KLASSCI — jamais resservie telle quelle avec son état
avant suppression (ex. `is_active` figé à sa valeur d'avant la suppression,
`visio_active` etc.).

## R3 — Non-régression : cas nominal (pas de ligne trashed) inchangé

Les deux call sites doivent conserver leur comportement actuel quand AUCUNE
ligne trashed n'existe pour cette clé :
- `upsertSeance()` : ligne active trouvée → update ; aucune ligne (active ou
  trashed) → create (comportement `createSeance()` inchangé, y compris les
  notifications/sync classe pour une VRAIE création).
- `VisioToggleService::toggle()` : ligne active trouvée → update (`$visio->wasRecentlyCreated`
  reste `false`) ; aucune ligne → create (`$visio->wasRecentlyCreated` reste
  `true`, déclenche le `created_by` + notifications comme aujourd'hui).

## R4 — Isolation tenant préservée

`upsertSeance()` scope explicitement par `institution_id` (contexte job, scope
global inerte) — le lookup `withTrashed()` DOIT continuer à filtrer par
`institution_id` explicitement (ne jamais restaurer/mettre à jour une ligne
trashed d'une AUTRE institution portant le même `klassci_seance_id` — rappel :
l'unique est composite précisément pour permettre des IDs KLASSCI identiques
entre institutions distinctes, cf. migration #473).

`VisioToggleService::toggle()` s'appuie sur le scope global `BelongsToInstitution`
(contexte HTTP authentifié, tenant déjà résolu par le middleware) — le lookup
`withTrashed()` DOIT continuer à passer par le scope global (ne pas faire
`withoutGlobalScope('institution')` ici, contrairement au job).

## Tests requis (TDD)

1. RED puis GREEN — `upsertSeance()` : séance active soft-deletée, resync avec
   même `klassci_seance_id`+`institution_id` → restaurée + à jour, pas
   d'exception, pas de doublon.
2. RED puis GREEN — `VisioToggleService::toggle()` : même scénario via
   l'appel HTTP-service direct.
3. Non-régression R3 : cas nominal (pas de ligne trashed) pour les deux call
   sites — comportement identique à avant (create vs update, flags
   `wasRecentlyCreated`/notifications).
4. Isolation tenant R4 : une ligne trashed d'une AUTRE institution avec le
   même `klassci_seance_id` ne doit JAMAIS être restaurée/affectée par
   `upsertSeance()` d'une institution différente.
