# Requirements — NotificationsController cross-tenant fix

**Issue GitHub** : [#98](https://github.com/ouedraogoissouf2012/lms_backend/issues/98)
**Spec workflow** : CONTRIBUTING.md §A
**Date** : 2026-05-17

---

## Contexte

L'audit `spec-security` du Batch 6 PHPStan (PR #97, NotificationsController) a remonté 2 findings cross-tenant pré-existants :

- **MEDIUM** : `create()` ([L197-219](app/Http/Controllers/API/NotificationsController.php#L197-L219)) — un coordinateur d'institution A peut créer une notification pour un user d'institution B. `CreateNotificationRequest::authorize()` ne fait que vérifier le rôle (`['coordinateur', 'superAdmin']`), pas le tenant.
- **LOW** : `stats()` ([L224-262](app/Http/Controllers/API/NotificationsController.php#L224-L262)) — aggrégations `DB::table('notifications')->...` sans filtre `institution_id`. Tout admin/coordinateur voit les comptages globaux cross-tenant. Le cache key `'notifications_stats_admin'` est global.

---

## Objectifs

1. Bloquer la création cross-tenant dans `create()` (sauf `supradmin`)
2. Restreindre `stats()` à l'institution du caller (sauf `supradmin`)
3. Préserver le comportement légitime (coordinateur/admin intra-tenant + supradmin cross-tenant)
4. Pas de régression sur les autres endpoints Notifications

---

## Requirements EARS

### REQ-1 — `create()` : isolation tenant stricte

**WHEN** un caller appelle `POST /api/admin/notifications/create`
**AND** son rôle n'est PAS `supradmin`
**THEN** la requête DOIT être rejetée (403) si `targetUser.institution_id !== caller.institution_id`

### REQ-2 — `create()` : bypass `supradmin`

**WHEN** un caller de rôle `supradmin` appelle `create()`
**THEN** la requête DOIT être autorisée sans vérification de tenant

### REQ-3 — `create()` : préservation du contrat existant

**WHEN** le caller est `coordinateur` ou `superAdmin` intra-tenant
**THEN** la création doit fonctionner comme avant (rôle déjà vérifié par middleware `role:` + `CreateNotificationRequest::authorize()`)

### REQ-4 — `stats()` : filtrage tenant

**WHEN** un caller non-`supradmin` appelle `GET /api/admin/notifications/stats`
**THEN** les aggrégations DOIVENT être scopées à `WHERE institution_id = caller.institution_id`
**AND** le cache key DOIT inclure `institution_id` pour éviter la fuite cross-tenant

### REQ-5 — `stats()` : bypass `supradmin`

**WHEN** un caller de rôle `supradmin` appelle `stats()`
**THEN** les aggrégations DOIVENT retourner les comptages globaux (cross-tenant) sans filtre

### REQ-6 — `stats()` : signature

**WHEN** le fix est appliqué
**THEN** `stats()` DOIT recevoir `Request $request` en paramètre (autowiring Laravel) pour accéder à l'utilisateur authentifié

### REQ-7 — Préservation des middlewares routes

**WHEN** le fix est déployé
**THEN** les middlewares routes restent inchangés : `auth:sanctum + role:coordinateur,superAdmin` pour les 2 endpoints

### REQ-8 — Tests d'isolation cross-tenant

**WHEN** la PR de fix est soumise
**THEN** elle DOIT inclure des tests d'isolation cross-tenant qui vérifient :

Pour `create()` :
- Coordinateur intra-tenant peut créer pour user intra ✓ (200)
- Coordinateur ne peut PAS créer pour user cross-tenant ✗ (403)
- superAdmin intra-tenant peut créer pour user intra ✓ (200)
- superAdmin ne peut PAS créer pour user cross-tenant ✗ (403)
- supradmin peut créer cross-tenant ✓ (200)

Pour `stats()` :
- Coordinateur intra-tenant voit comptages de son institution uniquement ✓
- superAdmin intra-tenant idem ✓
- supradmin voit comptages globaux cross-tenant ✓
- Cache keys sont distincts par institution

---

## Non-objectifs (hors scope)

- Migration `\App\Models\User::findOrFail` vers DI (§1.6 D — ticket séparé)
- Refactor `Notification::create` static (§1.6 D — ticket séparé)
- Refactor file-size NotificationsController (264>200 — ticket séparé)
- Ajout de `supradmin` dans la liste des rôles autorisés du middleware `role:` route (probable bug à investiguer séparément — actuellement `supradmin` ne peut PAS accéder à `create`/`stats` car non listé)

---

## Hypothèses critiques

1. La colonne `institution_id` existe sur la table `notifications` (vérifier dans migration)
2. Le rôle `supradmin` est censé avoir accès à `create`/`stats` cross-tenant (mais actuellement n'est pas dans `role:coordinateur,superAdmin` du middleware — donc en pratique il ne peut pas y accéder du tout)

---

## Risques

- **R1** : `supradmin` n'est pas dans la liste du middleware `role:` route → il ne peut pas accéder à ces endpoints du tout. Le fix REQ-2/REQ-5 (bypass supradmin) est théorique. À résoudre : soit ajouter `supradmin` au middleware route, soit accepter que supradmin n'a pas accès (et retirer REQ-2/REQ-5).
- **R2** : Le cache `'notifications_stats_admin'` global est pollué par les calls existants. Lors du déploiement, premier call par institution → cache miss → recalcul. Pas de risque, juste un cache cold start.
- **R3** : `stats()` ajout de `Request $request` est rétrocompatible (Laravel autowiring).

---

## Acceptance criteria

- [ ] `CreateNotificationRequest::authorize()` vérifie tenant + supradmin bypass (REQ-1, REQ-2)
- [ ] `NotificationsController::stats()` filtre par tenant + supradmin bypass + cache key par institution (REQ-4, REQ-5, REQ-6)
- [ ] Aucun appel à `User::isAdmin()` introduit (sémantique ambiguë évitée — listes de rôles explicites)
- [ ] Tests intégration HTTP : matrice tenant × rôle pour `create` (5 cas) et `stats` (4 cas)
- [ ] PHPStan reste à 0 errors hors baseline
- [ ] 3 audits PASS (security strict obligatoire)
- [ ] PR ouverte avec `closes #98`
- [ ] R1 résolu : décider si `supradmin` doit être ajouté au middleware route ou si REQ-2/REQ-5 doivent être retirés (décision documentée dans design.md)
