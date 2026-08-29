# Requirements — Isolation tenant des stats (supradmin plateforme strict) (#497)

## Contexte & preuves

Deux services calculent « voir tout, cross-tenant » avec `$caller->asRoleEnum() === Role::Supradmin` :
- `app/Services/File/FileQueryService.php:153` → `GET /api/files/stats` (route `content.php:158`, **aucun `role:` gate**)
- `app/Services/Notification/NotificationQueryService.php:183` → `GET /api/admin/notifications/stats` (route `admin.php:90`, `role:coordinateur,superAdmin,supradmin` laisse passer `superAdmin`)

Or `Role::tryFromString` (`app/Enums/Role.php:66`) mappe **`'superAdmin'` (admin d'INSTITUTION, `institution_id` non-null) ET `'supradmin'` (gestionnaire PLATEFORME)** vers la même case `Role::Supradmin`. Donc un `superAdmin` d'institution satisfait `asRoleEnum() === Role::Supradmin` → il obtient les stats **de toutes les institutions** + une clé de cache **globale partagée** (`files_stats_global` / `notifications_stats_global`).

`superAdmin` est une valeur de `users.role` **réelle** (`app/Rules/AssignableRole.php:20-23`, initialisable via KLASSCI à la création).

### Le fix existe déjà — mais dupliqué
6 sites sensibles utilisent la comparaison **stricte** `role === 'supradmin'` **avec un commentaire d'avertissement répété** « NE PAS migrer vers asRoleEnum() » :
`ChecksFileAuthorization.php:63,99`, `ChecksForumAuthorization.php:87`, `CreateNotificationRequest.php:69`, `ViewAuditLogRequest.php:16`, `RateLimitServiceProvider.php:81`. Les 2 services stats ont oublié cette défense.

### Les tests verrouillent le comportement BUGGÉ
- `tests/Feature/Notifications/NotificationStatsAuthorizationTest.php:89-100` — `test_superAdmin_alias_is_treated_as_supradmin_and_sees_global_stats` asserte `total=5` (la fuite) comme si c'était correct. **À inverser.**
- `tests/Feature/Files/FileStatsAuthorizationTest.php` — teste `admin` (scopé, OK) et `supradmin` (global, OK) mais **jamais `superAdmin`** → trou de test.

## Décisions

- **D1** : un `superAdmin` (admin d'institution) est scopé à SON institution ; seul `supradmin` (plateforme, `institution_id=NULL`) voit global.
- **D2** : centraliser le concept « plateforme » dans un helper unique plutôt que dupliquer `role === 'supradmin'` une 7e/8e fois (le pattern dupliqué est un finding DRY de l'audit #496).

## Portée

- **IN** : helper de rôle « plateforme », correction des 2 services stats, correction/complétion des 2 tests.
- **OUT** : la conflation `Role::tryFromString` elle-même (ne PAS la changer — `superAdmin→Supradmin` est utilisé légitimement par `isAdmin()`/`isStaff()` pour l'accès intra-tenant ; la corriger casserait `isAdmin`). On corrige les sites qui confondent « admin large » et « plateforme », pas l'enum.
- **OUT** : migration des 6 sites stricts existants — traitée en décision de design (option), pas imposée ici.

## Exigences (EARS)

**REQ-1 — Helper « plateforme » unique**
THE SYSTEM SHALL exposer sur `User` (via `InteractsWithRoles`) une méthode dédiée signifiant « gestionnaire plateforme cross-tenant » basée sur la comparaison **stricte** `role === 'supradmin'` (jamais l'enum conflé), avec le commentaire explicatif centralisé UNE fois.

**REQ-2 — Stats fichiers scopées pour superAdmin**
WHEN un utilisateur `role='superAdmin'` (institution_id non-null) appelle `GET /api/files/stats`, THE SYSTEM SHALL ne compter QUE les fichiers de SON institution (comme un `admin`), et utiliser une clé de cache **namespacée par institution** (jamais `files_stats_global`).

**REQ-3 — Stats notifications scopées pour superAdmin**
WHEN un `role='superAdmin'` appelle `GET /api/admin/notifications/stats`, THE SYSTEM SHALL ne compter QUE les notifications de SON institution, clé de cache namespacée par institution.

**REQ-4 — Plateforme (supradmin) inchangé**
WHEN un `role='supradmin'` (institution_id NULL) appelle l'un des 2 endpoints, THE SYSTEM SHALL continuer de voir les counts globaux cross-tenant (comportement plateforme préservé).

**REQ-5 — Rôles intra-tenant inchangés**
THE SYSTEM SHALL préserver le comportement actuel de `admin`/`coordinateur`/`enseignant` (scopés) et `étudiant` (scopé + user-scoped fichiers).

**REQ-6 — Tests corrigés (plus de faux vert)**
THE SYSTEM SHALL inverser le test notifications qui verrouille la fuite (superAdmin → scopé, `total=3`) et ajouter un test `superAdmin` scopé pour les fichiers. Le comportement `supradmin` global reste testé.

## Critères d'acceptation

1. `superAdmin` de l'institution A → `/api/files/stats` = fichiers de A uniquement ; `/api/admin/notifications/stats` = notifs de A uniquement.
2. `supradmin` (plateforme) → counts globaux sur les 2 endpoints (inchangé).
3. `admin`/`coordinateur`/`étudiant` → inchangés.
4. Clé de cache d'un `superAdmin` ≠ clé globale (pas d'empoisonnement cross-tenant).
5. Aucun autre site « stats/counts » ne reproduit `asRoleEnum() === Role::Supradmin` (grep de contrôle).
6. `php artisan test` 100 %, PHPStan level 9 vert, garde tailles OK.

## Q15 — Critères d'invalidation

- ❌ Modifier `Role::tryFromString` (casse `isAdmin`/`isStaff` → accès intra-tenant).
- ❌ `supradmin` plateforme perd l'accès global (sur-correction).
- ❌ Laisser une clé cache globale accessible à un `superAdmin`.
- ❌ Garder le test qui asserte `total=5` pour un superAdmin (faux vert persistant).
- ❌ Dupliquer `role === 'supradmin'` en ligne dans les services au lieu d'un helper (dette DRY aggravée).
