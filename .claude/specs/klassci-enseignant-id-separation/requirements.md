# klassci_enseignant_id separation — Empêcher l'IDOR cross-enseignant via klassci_data

> Issue GitHub : [#119 [security HIGH] klassci_data["enseignant_id"] — pattern IDOR identique à CRITICAL-05, 3 FormRequests touchées](https://github.com/ouedraogoissouf2012/lms_backend/issues/119)
>
> Identifié par l'audit `spec-security` (rapport INFO-1) de la PR [#118](https://github.com/ouedraogoissouf2012/lms_backend/pull/118) qui a fermé CRITICAL-05 (#34). Pattern racine identique — différentes colonnes consommatrices.

## Contexte

Trois FormRequests lisent l'identifiant d'enseignant d'un utilisateur depuis le blob JSON `users.klassci_data` pour autoriser les actions sur les évaluations :

```php
// app/Http/Requests/DeleteEvaluationRequest.php:45 (et 2 jumeaux)
$userKlassciEnseignantId = data_get($user->klassci_data, 'enseignant_id');
if (!$user->isAdmin() && $evaluation->klassci_enseignant_id !== $userKlassciEnseignantId) {
    return false;
}
```

Or `users.klassci_data` est **écrit en bloc** :
1. Par `AuthController::syncUserFromKlassci()` lors de chaque login (CREATE + UPDATE)
2. Par `EnsureKlassciSync::handle()` lors du re-sync passif 24h

Le middleware EnsureKlassciSync (post-PR #118) **NE met PLUS à jour** `users.role` ni `users.email`, mais **continue à réécrire `users.klassci_data` en bloc** :

```php
// app/Http/Middleware/EnsureKlassciSync.php:99-105 (état post-PR #118)
$user->update([
    'name'              => $klassciUser['nom'] ?? ...,
    'klassci_role'      => $klassciUser['role'] ?? $user->klassci_role,
    'klassci_data'      => json_encode($klassciUser),  // ← BLOB ÉCRASÉ
    'last_klassci_sync' => now(),
]);
```

Un attaquant contrôlant le serveur KLASSCI (compromission externe, ou admin KLASSCI d'un tenant auto-hébergé sans gouvernance LMS) peut donc faire renvoyer par `/auth/me` :

```json
{ "data": { "user": { "id": <user-X-klassci-id>, "enseignant_id": 999, ... } } }
```

Au prochain re-sync passif (≤ 24h), `user-X.klassci_data['enseignant_id']` devient `999`. L'utilisateur peut alors **supprimer, publier ou modifier les évaluations de l'enseignant id=999** — IDOR cross-user complet sur le domaine évaluations.

## Scénario d'attaque

1. Attaquant compromet le serveur KLASSCI d'une école (ou est admin KLASSCI d'un tenant auto-hébergé sans gouvernance LMS)
2. Attaquant modifie le payload `auth/me` pour user-X : `enseignant_id` = id de l'enseignant victime (`999`)
3. User-X attend ≤ 24h (re-sync passif via `EnsureKlassciSync`)
4. User-X appelle `DELETE /api/evaluations/{id}` où `{id}` appartient à l'enseignant `999`
5. `DeleteEvaluationRequest::authorize()` lit `enseignant_id` depuis le blob écrasé → match → **200 OK, évaluation supprimée**

L'attaque est :
- **Silencieuse** (aucune trace dans `users.role` post-PR #118 ; `klassci_data` ne déclenche pas de log de divergence)
- **Persistante** (le blob reste écrasé jusqu'au prochain login légitime, qui le ré-écrit potentiellement avec une autre valeur attaquante)
- **Bypass complet de l'autorisation** sur les 3 endpoints `DELETE/POST publish/PUT` évaluations

## Solution

Suivre **exactement le pattern de CRITICAL-05** (PR #118) : sortir la donnée sensible du blob volatile vers une **colonne dédiée write-once**.

## Requirements (EARS)

### REQ-1 — Source de vérité unique pour l'ownership enseignant

La colonne `users.klassci_enseignant_id` (BIGINT nullable indexée) SHALL être la **source de vérité unique** pour toutes les décisions d'autorisation basées sur l'identité enseignant KLASSCI d'un utilisateur.

WHEN un consommateur (FormRequest, controller, policy, service) prend une décision d'autorisation basée sur l'identité enseignant KLASSCI,
THE système SHALL lire `$user->klassci_enseignant_id` et SHALL ne PAS lire `data_get($user->klassci_data, 'enseignant_id')`.

### REQ-2 — Nouvelle colonne `klassci_enseignant_id` + migration idempotente

WHERE la table `users` est modifiée,
THE migration SHALL ajouter une colonne `klassci_enseignant_id` de type `unsignedBigInteger` nullable, indexée, placée après `klassci_role`.

THE migration SHALL être **idempotente** (`Schema::hasColumn` guard) pour survivre à une ré-exécution accidentelle.

WHEN un utilisateur existant est backfillé,
THE migration SHALL exécuter `users.klassci_enseignant_id = data_get(klassci_data, 'enseignant_id')` pour tous les utilisateurs ayant `klassci_id` non null, par batches `chunkById(1000)` pour scalabilité 200k+ users.

WHEN la migration est exécutée en down,
THE migration SHALL drop la colonne `klassci_enseignant_id` et son index proprement.

### REQ-3 — Initialisation write-once au CREATE uniquement

WHEN un nouvel utilisateur est créé via `AuthController::syncUserFromKlassci()` (1ère connexion KLASSCI),
THE système SHALL initialiser `klassci_enseignant_id` avec `$klassciUser['enseignant_id'] ?? null` (valeur de KLASSCI au moment du sign-up).

IF l'utilisateur existe déjà (branche UPDATE de `syncUserFromKlassci`),
THE système SHALL ne PAS modifier `klassci_enseignant_id` (write-once).

Justification : un user ne change pas d'identité enseignant côté KLASSCI sans intervention admin manuelle. Aucun cas légitime de re-write automatique. La valeur initiale, plantée au sign-up alors que le user contrôle activement son compte KLASSCI, est la racine de confiance.

### REQ-4 — Re-sync 24h n'écrit jamais `klassci_enseignant_id`

WHEN le middleware `EnsureKlassciSync` re-synchronise un utilisateur,
THE middleware SHALL ne PAS inclure `klassci_enseignant_id` dans le `$user->update([...])`.

THE middleware SHALL continuer à écrire `klassci_data` (blob informationnel) comme aujourd'hui — la colonne dédiée est ce qui compte pour l'autorisation, le blob ne sert que d'archive et de fallback display.

### REQ-5 — Migration des 3 FormRequests vulnérables

WHERE les FormRequests `DeleteEvaluationRequest`, `PublishEvaluationRequest`, `UpdateEvaluationRequest` lisent l'identité enseignant pour authorize,
THE remplacement SHALL être strict :

```php
// AVANT (vulnérable)
$userKlassciEnseignantId = data_get($user->klassci_data, 'enseignant_id');

// APRÈS (sûr)
$userKlassciEnseignantId = $user->klassci_enseignant_id;
```

THE code SHALL ne PAS implémenter de fallback vers le blob (`?? data_get($user->klassci_data, ...)`). Si `klassci_enseignant_id` est `NULL` (user sans contexte KLASSCI enseignant, ou backfill incomplet), le check d'ownership échoue → `403`. C'est le comportement sécurisé : un user sans contexte enseignant n'a pas à pouvoir modifier des évaluations.

### REQ-6 — User model : `$fillable` + `@property`

WHERE le model `User` est modifié,
THE classe SHALL ajouter `'klassci_enseignant_id'` à `$fillable` (immédiatement après `'klassci_role'`).

THE PHPDoc de classe SHALL ajouter `@property int|null $klassci_enseignant_id` avec note « Initialisé au sign-up KLASSCI ; jamais réécrit par re-sync ; source d'autorité unique pour les checks d'ownership enseignant. ».

### REQ-7 — Tests obligatoires

WHEN les tests sont écrits,
THE suite SHALL couvrir au minimum :

| # | Test | Description | Assertion clé |
|---|---|---|---|
| 1 | `test_create_initializes_klassci_enseignant_id_from_payload` | Nouveau user via `syncUserFromKlassci` avec `enseignant_id=42` | `user->klassci_enseignant_id === 42` |
| 2 | `test_update_does_not_overwrite_klassci_enseignant_id` | User existant `klassci_enseignant_id=42`, re-login avec payload `enseignant_id=999` (attaquant) | `user->klassci_enseignant_id === 42` |
| 3 | `test_resync_passive_does_not_modify_klassci_enseignant_id` | User stale, KLASSCI renvoie `enseignant_id=999`, middleware re-sync | `user->klassci_enseignant_id === <initial>` |
| 4 | `test_delete_evaluation_authorized_for_owner_via_dedicated_column` | User `klassci_enseignant_id=42`, eval `klassci_enseignant_id=42` | `DELETE /api/evaluations/{id}` → 200 |
| 5 | `test_delete_evaluation_blocked_for_klassci_data_blob_attacker` | User `klassci_enseignant_id=42`, `klassci_data['enseignant_id']=999` (blob écrasé par attaque KLASSCI), eval `klassci_enseignant_id=999` | `DELETE` → 403 (FormRequest ignore le blob) |
| 6 | `test_publish_evaluation_blocked_for_klassci_data_blob_attacker` | Idem 5 mais sur `POST /publish` | 403 |
| 7 | `test_update_evaluation_blocked_for_klassci_data_blob_attacker` | Idem 5 mais sur `PUT /api/evaluations/{id}` | 403 |
| 8 | `test_migration_backfill_copies_enseignant_id_from_blob` | User pre-existant avec `klassci_data['enseignant_id']=42` et `klassci_enseignant_id NULL` → migration tournée → `klassci_enseignant_id=42` | column populated |
| 9 | `test_user_without_klassci_enseignant_id_cannot_delete_evaluation` | User avec `klassci_enseignant_id=NULL` (ex : étudiant, ou user pre-backfill) | `DELETE` → 403 |
| 10 | `test_admin_can_delete_evaluation_regardless_of_klassci_enseignant_id` | User `role=supradmin`, `klassci_enseignant_id=NULL`, eval random | `DELETE` → 200 (bypass admin existant préservé) |

### REQ-8 — Aucune régression sur les workflows légitimes existants

WHEN les tests Feature des routes évaluations (`tests/Feature/Quiz/`, `tests/Feature/Forum/`, etc.) sont exécutés,
THE suite SHALL passer 100% sans modification (aucun consommateur de `$evaluation->klassci_enseignant_id` ne doit être altéré).

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|---|---|
| Refactor des 3 FormRequests vers un trait commun (`ChecksEvaluationOwnership`) | La duplication 3× existe déjà ; la traiter ici ajoute du churn et brouille le diff sécurité. À ouvrir en issue refactor séparée. |
| Auditer/réparer rétroactivement les évaluations supprimées via l'exploitation passée | Aucun cas connu, et la base actuelle ne permet pas de distinguer une suppression légitime d'une exploitation. Hors scope ; mitigation côté ops (audit manuel si soupçon). |
| Étendre le pattern write-once à `klassci_data['etudiant_id']` ou `klassci_data['admin_id']` | Aucun consommateur d'autorisation identifié sur ces clés (vérifié par grep). À surveiller si futur code en consomme. |
| Bloquer le re-sync `klassci_data` entier (ne plus jamais réécrire le blob) | Casse l'affichage `klassci_data['avatar']`, `klassci_data['permissions']` qui sont des champs informatifs légitimement variables. Le blob reste un cache display ; seules les clés d'autorité sortent vers leurs colonnes dédiées. |
| Migration de la colonne `klassci_data` vers un type structuré (JSON typé) | YAGNI ; le blob n'est plus consulté pour l'autorisation après cette PR. |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ Tous les REQ-1 à REQ-8 sont implémentés et couverts par les tests listés en REQ-7
2. ✓ `vendor/bin/phpstan analyse` reste à `[OK] No errors`
3. ✓ `vendor/bin/phpunit tests/` passe 100% (avec `pdo_pgsql` en CI)
4. ✓ `php artisan migrate:fresh --seed` et `php artisan migrate:rollback` fonctionnent sans erreur
5. ✓ `spec-security` audit retourne 0 finding HIGH/CRITICAL
6. ✓ `spec-architect` audit retourne 0 finding HIGH/CRITICAL
7. ✓ `spec-reviewer` audit retourne MERGE-READY
8. ✓ L'issue GitHub #119 sera fermée manuellement au merge (la mention `closes #119` n'auto-ferme pas sur la branche `lms` ≠ default)

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **`klassci_data['enseignant_id']` devient légitimement variable** côté KLASSCI (ex : refactor backend KLASSCI où l'enseignant_id peut changer après un mariage / changement de matricule). Dans ce cas, write-once devient inadapté ; il faut un mécanisme de re-binding manuel via UI admin LMS, pas un re-write automatique.
2. **Le payload KLASSCI commence à inclure plusieurs `enseignant_id`** (un user qui enseigne dans plusieurs établissements partagés via KLASSCI multi-school). Le mapping `BIGINT` simple devient inadapté ; il faut une table `user_klassci_enseignant_roles`.
3. **Un audit RGPD ou métier impose la cohérence stricte `klassci_data['enseignant_id']` = `users.klassci_enseignant_id` en permanence**. Auquel cas il faut documenter clairement quel champ a la précédence et établir un job de réconciliation.

Aucun de ces 3 cas n'est connu aujourd'hui. La solution tient.
