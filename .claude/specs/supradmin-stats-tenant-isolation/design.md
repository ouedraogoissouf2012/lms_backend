# Design — Isolation tenant des stats (supradmin plateforme strict) (#497)

## 1. Helper « plateforme » unique (REQ-1)

Ajouter à `app/Models/Concerns/InteractsWithRoles.php` :

```php
/**
 * Gestionnaire PLATEFORME (cross-tenant, institution_id = NULL).
 *
 * Comparaison STRICTE `role === 'supradmin'` — volontairement PAS
 * `asRoleEnum()`, qui normaliserait aussi `'superAdmin'` (admin d'INSTITUTION,
 * intra-tenant) vers Role::Supradmin et briserait l'isolation tenant.
 * cf. issue #102/#497, .claude/specs/file-idor-cross-tenant/design.md §2.
 *
 * À utiliser pour tout privilège cross-tenant ; NE PAS confondre avec
 * {@see isAdmin()} qui est « admin au sens large » (intra-tenant).
 */
public function isPlatformSupradmin(): bool
{
    return $this->role === 'supradmin';
}
```

Le commentaire d'avertissement (aujourd'hui dupliqué ~6 fois) vit désormais **une seule fois**, sur le helper.

## 2. Correction des 2 services (REQ-2, REQ-3, REQ-4)

### FileQueryService.php:153
```php
- $isSupradmin = $caller->asRoleEnum() === Role::Supradmin;
+ $isSupradmin = $caller->isPlatformSupradmin();
```
La clé cache et `applyScope` en découlent automatiquement : un `superAdmin`
(`isPlatformSupradmin() === false`) prend la branche `files_stats_inst_{institution_id}`
et le `where('institution_id', ...)`. L'import `use App\Enums\Role;` devient
inutile s'il n'est plus référencé (à vérifier/retirer).

### NotificationQueryService.php:183
```php
- $isSupradmin = $caller->asRoleEnum() === Role::Supradmin;
+ $isSupradmin = $caller->isPlatformSupradmin();
```
Idem : clé `notifications_stats_inst_{institution_id}` + `where('institution_id')`.

**Aucune autre ligne ne change** dans ces services : la logique de scope et de
cache est déjà correcte, elle était juste pilotée par le mauvais prédicat.

## 3. Correction des tests (REQ-6)

### NotificationStatsAuthorizationTest.php:89-100 — INVERSER
Le test `test_superAdmin_alias_is_treated_as_supradmin_and_sees_global_stats`
asserte `total=5` (la fuite). Le remplacer par
`test_superAdmin_is_scoped_to_their_institution` :
- user `role='superAdmin'`, `institution_id = instA`
- assert `data.total === 3` (institution A uniquement), PAS 5.

### FileStatsAuthorizationTest.php — AJOUTER
Nouveau `test_superadmin_institution_is_scoped_to_their_institution` :
- user `role='superAdmin'`, `institution_id = instA`
- assert `data.total_files === 3` (pas 5).

Les tests `supradmin` (plateforme, global, `total=5`) restent inchangés (REQ-4).

## 4. Décision de périmètre — migration des 6 sites stricts

Les 6 sites existants dupliquent `role === 'supradmin'` + le commentaire :
`ChecksFileAuthorization:63,99`, `ChecksForumAuthorization:87`,
`CreateNotificationRequest:69`, `ViewAuditLogRequest:16`,
`RateLimitServiceProvider:81`.

**DÉCISION (arrêtée avec le user) : périmètre MINIMAL.**
Créer le helper + corriger UNIQUEMENT les 2 services buggés + les 2 tests.
**Ne PAS** migrer les 6 sites stricts existants dans cette PR.

Justification : d'autres issues de l'audit non encore traitées touchent la zone
rôle/auth ; déborder dans les 6 sites stricts (code déjà correct) risquerait un
conflit avec ces travaux à venir. Le nettoyage DRY des 6 sites (les faire pointer
vers `isPlatformSupradmin()`) est **dette tracée**, à traiter séparément quand la
zone rôle sera stabilisée. Respecte « une issue = un périmètre ».

## 5. Contrôle anti-récidive (REQ, AC5)

Grep de contrôle après fix : `asRoleEnum() === Role::Supradmin` et
`=== Role::Supradmin` ne doivent plus apparaître dans un contexte « voir tout /
cross-tenant » (les usages légitimes restants — s'il y en a — doivent être
justifiés).

## 6. Fichiers touchés

| Fichier | Nature | Option |
|---|---|---|
| `app/Models/Concerns/InteractsWithRoles.php` | + `isPlatformSupradmin()` | A & B |
| `app/Services/File/FileQueryService.php` | `:153` → helper (+ retrait import Role si inutile) | A & B |
| `app/Services/Notification/NotificationQueryService.php` | `:183` → helper | A & B |
| `tests/Feature/Notifications/NotificationStatsAuthorizationTest.php` | inverser test superAdmin | A & B |
| `tests/Feature/Files/FileStatsAuthorizationTest.php` | + test superAdmin scopé | A & B |
| ~~6 sites stricts~~ | **hors périmètre** (dette DRY tracée) | — |

`InteractsWithRoles` reste ≤ limite (ajout ~15 lignes) ; services inchangés en taille.
