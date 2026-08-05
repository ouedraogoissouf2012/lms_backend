# Tasks — Isolation tenant des stats (supradmin plateforme strict) (#497)

Ordre TDD : RED → GREEN → non-régression → vérif. Périmètre MINIMAL (pas de
migration des 6 sites stricts).

## 1. Tests RED (verrouiller le bon comportement)

- [ ] **1.1** `tests/Feature/Files/FileStatsAuthorizationTest.php` — AJOUTER
  `test_superadmin_institution_is_scoped_to_their_institution` :
  user `role='superAdmin'`, `institution_id = instA` → `GET /api/files/stats` →
  `data.total_files === 3` (institution A, PAS 5). _(REQ-2, AC1.)_
  **Doit échouer** aujourd'hui (superAdmin voit 5 via la conflation).
- [ ] **1.2** `tests/Feature/Notifications/NotificationStatsAuthorizationTest.php` —
  **INVERSER** `test_superAdmin_alias_is_treated_as_supradmin_and_sees_global_stats`
  (`:89-100`) → le renommer `test_superAdmin_is_scoped_to_their_institution` :
  user `role='superAdmin'`, `institution_id = instA` → `data.total === 3` (PAS 5).
  _(REQ-3, REQ-6, AC1.)_ **Doit échouer** aujourd'hui.
- [ ] **1.3** Vérifier que les tests `supradmin` plateforme (global, `total=5`)
  et `admin`/`coordinateur` (scopés) **restent présents et inchangés** (REQ-4/5).
- [ ] **Lancer 1.1/1.2 → RED.**

## 2. Implémentation GREEN

- [ ] **2.1** `app/Models/Concerns/InteractsWithRoles.php` — ajouter
  `isPlatformSupradmin(): bool` (`return $this->role === 'supradmin';`) avec le
  docblock d'avertissement centralisé. _(REQ-1.)_
- [ ] **2.2** `app/Services/File/FileQueryService.php:153` —
  `$isSupradmin = $caller->isPlatformSupradmin();`. Retirer `use App\Enums\Role;`
  s'il devient inutilisé. _(REQ-2.)_
- [ ] **2.3** `app/Services/Notification/NotificationQueryService.php:183` — idem.
  Retirer l'import `Role` si inutilisé. _(REQ-3.)_
- [ ] **2.4** Lancer 1.1/1.2 → **GREEN**.

## 3. Non-régression

- [ ] **3.1** `php artisan test tests/Feature/Files/ tests/Feature/Notifications/`
  → 100 % (admin/coordinateur/étudiant/supradmin inchangés).
- [ ] **3.2** Suites des consommateurs du rôle : `tests/Feature/Security/`
  (autorisation), + toute suite touchant `isAdmin`/`isStaff` → 100 % (le helper
  est additif, `asRoleEnum`/`isAdmin` inchangés).

## 4. Vérification

- [ ] **4.1** PHPStan level 9 sur les 3 fichiers touchés → 0 erreur.
- [ ] **4.2** Garde tailles : `InteractsWithRoles` ≤ limite (trait, pas modèle),
  services inchangés.
- [ ] **4.3** Grep anti-récidive : `asRoleEnum() === Role::Supradmin` ne doit
  plus apparaître dans un contexte « stats/counts cross-tenant ». Documenter les
  usages légitimes restants s'il y en a. _(AC5.)_

## 5. Clôture

- [ ] **5.1** Après merge PR : fermer #497 + cocher la case dans l'épique #496.
  Noter la dette DRY (migration des 6 sites) comme suite possible.

## Traçabilité exigences → tâches

| Exigence | Tâche(s) |
|---|---|
| REQ-1 (helper) | 2.1 |
| REQ-2 (files superAdmin scopé) | 1.1, 2.2 |
| REQ-3 (notifs superAdmin scopé) | 1.2, 2.3 |
| REQ-4 (supradmin global inchangé) | 1.3, 3.1 |
| REQ-5 (intra-tenant inchangé) | 1.3, 3.1, 3.2 |
| REQ-6 (tests corrigés) | 1.1, 1.2 |
