# Design — #542 [P2] Séance soft-deleted bloque la recréation/sync

> **Révisé après `/code-review effort max`.** Le §3 ci-dessous rejetait
> initialement l'extraction d'un helper partagé (« logique trop triviale »).
> La revue a montré que (a) `restore()` seul ne suffit pas — il fallait AUSSI
> réinitialiser `is_active`/`archived_at`/`archive_reason`, un vrai gap
> fonctionnel, pas de la triviale — et (b) une fois ce correctif ajouté aux 2
> call sites, la duplication est devenue une dette réelle. `SeanceRestoreGuard`
> (classe statique, `app/Services/Seances/SeanceRestoreGuard.php`, calquée sur
> `KlassciPayload` du même répertoire) a donc été extraite après tout — voir
> le détail dans `.claude/specs/542-seance-soft-delete-upsert/tasks.md`
> section « Révisé après code-review ». `VisioToggleService::toggle()` a
> aussi gagné un `catch (UniqueConstraintViolationException)` autour de son
> `Seance::create()` (retry-sur-conflit, régression vs `updateOrCreate()`
> détectée en revue) et l'extraction de `restoreOrCreateVisio()` en méthode
> privée dédiée.

## 1. `KlassciSeancesSyncService::upsertSeance()`

Lookup actuel (exclut implicitement les lignes trashed — `SoftDeletingScope`
global non retiré) :

```php
$seanceLocal = Seance::withoutGlobalScope('institution')
    ->where('institution_id', $institutionId)
    ->where('klassci_seance_id', $klassciSeanceId)
    ->first();

if ($seanceLocal) {
    $this->cacheBuilder->applyTo($seanceLocal, $cacheData);
    return;
}

$this->createSeance(...);
```

Correctif — `withTrashed()` + restauration explicite si trashed :

```php
$seanceLocal = Seance::withoutGlobalScope('institution')
    ->withTrashed()
    ->where('institution_id', $institutionId)
    ->where('klassci_seance_id', $klassciSeanceId)
    ->first();

if ($seanceLocal) {
    if ($seanceLocal->trashed()) {
        $seanceLocal->restore();
    }
    $this->cacheBuilder->applyTo($seanceLocal, $cacheData);
    return;
}

$this->createSeance(...);
```

`withoutGlobalScope('institution')` reste EN PLUS de `withTrashed()` — deux
scopes globaux distincts (`institution` custom + `SoftDeletingScope` natif
Eloquent), retirer l'un ne retire pas l'autre. Le filtre explicite
`->where('institution_id', $institutionId)` (R4) est INCHANGÉ — c'est lui qui
garantit qu'une ligne trashed d'une AUTRE institution avec le même
`klassci_seance_id` n'est jamais touchée.

`$cacheBuilder->applyTo()` (inchangé, `app/Services/Seances/SeanceCacheDataBuilder.php`)
écrase déjà tous les champs pertinents avec les données fraîches de KLASSCI —
aucun risque de « resurrection » de données obsolètes (R2) : la restauration
ne remet que `deleted_at = null`, tout le reste est immédiatement réécrit par
`applyTo()` dans la même méthode, avant tout retour.

## 2. `VisioToggleService::toggle()`

`Seance::updateOrCreate()` ne supporte pas nativement `withTrashed()` (son
lookup interne utilise le query builder par défaut du modèle, donc respecte
la `SoftDeletingScope`). Remplacé par un lookup+restore/update-ou-create
manuel, équivalent fonctionnel exact :

```php
$attributes = [
    'klassci_matiere_id' => null,
    'klassci_classe_id' => null,
    'klassci_enseignant_id' => null,
    'visio_enabled' => $enabled,
    'visio_type' => $enabled ? $visioType : null,
    'visio_status' => $enabled ? 'programmee' : null,
    'visio_room_id' => $enabled ? SecureVisioRoomIdGenerator::make() : null,
    'visio_active' => false,
    'updated_by' => $user->id,
];

$visio = Seance::withTrashed()->where('klassci_seance_id', $seanceId)->first();

if ($visio) {
    if ($visio->trashed()) {
        $visio->restore();
    }
    $visio->update($attributes);
} else {
    $visio = Seance::create(['klassci_seance_id' => $seanceId] + $attributes);
}

if ($visio->wasRecentlyCreated) {
    $visio->created_by = $user->id;
    $visio->save();
}
```

`withTrashed()` seul (pas de `withoutGlobalScope('institution')`) — R4 :
cette méthode s'exécute en contexte HTTP authentifié, le scope global
`BelongsToInstitution` est actif et continue de filtrer implicitement par le
tenant courant, comme avant ce correctif.

`wasRecentlyCreated` reste correct : `true` uniquement quand la branche
`Seance::create()` s'exécute (comportement Eloquent natif d'un INSERT),
`false` dans la branche restore-puis-update — préserve exactement la
sémantique R3 dont dépend le bloc `created_by`/notifications qui suit.

## 3. Alternatives écartées (Q12 self-critique)

1. **Index unique partiel filtré sur `deleted_at IS NULL`** (migration DB) —
   écarté : (a) hors périmètre de fichiers (migration ≠ `app/Services/Seances/*`
   ni les deux Jobs assignés) ; (b) MySQL ne supporte pas nativement les index
   uniques filtrés (contrairement à PostgreSQL) — solution non portable sans
   contournement (colonne générée), complexité disproportionnée face au fix
   applicatif `withTrashed()` déjà suffisant.
2. **Forcer un `deleted_at` NULL au `restore()` via un observer global sur
   `Seance`** — écarté : `restore()` fait déjà exactement ça nativement
   (`SoftDeletes::restore()`), un observer serait une duplication inutile de
   comportement déjà correct.
3. **Extraire un helper partagé `restoreIfTrashed(?Seance $seance)`** —
   écarté : la logique commune se réduit à 2 lignes (`if trashed → restore`),
   et les deux call sites diffèrent sur le SCOPING du lookup (job vs HTTP,
   `withoutGlobalScope('institution')` explicite vs scope global implicite) —
   extraire forcerait soit à dupliquer quand même la construction de la
   requête, soit à masquer une différence de comportement réellement
   significative (R4) derrière un helper trop générique. Inline dans les 2
   méthodes, cohérent avec « pas d'abstraction prématurée ».

## 4. Tests

1. RED puis GREEN — `upsertSeance()` : séance active soft-deletée (même
   `klassci_seance_id`+`institution_id`), resync → restaurée, `deleted_at`
   redevient `null`, données à jour, pas d'exception/pas de doublon en base.
2. RED puis GREEN — `VisioToggleService::toggle()` : même scénario, appel
   direct du service (pas besoin de monter toute la route HTTP).
3. Non-régression cas nominal (aucune ligne trashed) : les deux call sites,
   assertions sur create vs update inchangées (y compris `wasRecentlyCreated`
   pour `VisioToggleService`, et `createSeance()`'s notifications/sync classe
   pour `upsertSeance()`).
4. Isolation tenant (R4) : `upsertSeance()` — ligne trashed de l'institution B
   avec le même `klassci_seance_id`, resync pour l'institution A → la ligne de
   B reste trashed et intacte, une NOUVELLE ligne est créée pour A (jamais de
   collision cross-tenant sur la restauration).
