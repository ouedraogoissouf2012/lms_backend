# Requirements — #565 Institution désactivée → lecture cross-tenant (fail-open)

> Sous-issue **P0** de #563 (audit 2026-08-15). Prouvée par test exécuté.
> Périmètre possédé par cette fenêtre : `app/Http/Middleware/ResolveInstitution.php`
> et `app/Models/Traits/BelongsToInstitution.php` (+ tests). Les ~57 services et les
> modèles `Institution`/`User` sont **hors périmètre** (fenêtres #566/#567).

## 1. Contexte & problème

`ResolveInstitution` résout le tenant à chaque requête API (middleware global,
`bootstrap/app.php:34`). Deux voies :

- **Voie bearer** (`resolveFromBearerToken`, l.92-105) : l'institution est déduite
  du `institution_id` de l'utilisateur porteur du token. Si l'institution est
  `is_active=false` **ou introuvable**, la condition `if ($institution && $institution->is_active)`
  est fausse → **la méthode retourne silencieusement sans poser le tenant**.
- **Voie header** (`X-Institution`, l.66-75) : une institution inactive renvoie déjà
  **400** (refus). Asymétrie révélatrice : la voie bearer, elle, laisse passer.

En aval, `BelongsToInstitution` (global scope, l.66-85) est **fail-open** : tenant
non résolu → `Log::warning` + `return` → le scope `where institution_id = ?` n'est
**pas** appliqué. Comme ~57 services/167 ne filtrent jamais explicitement par
`institution_id` et dépendent entièrement du scope global, la requête devient
**non scopée** → **fuite de données cross-tenant**.

**Vecteur d'attaque / incident** : un utilisateur d'une école désactivée (impayé,
suspension, résiliation) conserve un token valide et lit alors les données de
**toutes** les autres écoles de la plateforme. C'est l'invariant n°1 de KLASSCI
(isolation multi-tenant) qui tombe. Sévérité **P0 / CRITICAL**.

## 2. Requirements (EARS)

### R1 — Refus fail-secure de la voie bearer pour une institution désactivée
- **WHEN** une requête porte un bearer token dont l'utilisateur a un `institution_id`
  non nul **AND** l'institution correspondante est `is_active=false`,
  **THE SYSTEM SHALL** refuser la requête avec une réponse HTTP **403** JSON
  `{success:false, message:<clair, sans détail technique>}` **AND** ne poser aucun
  tenant, **AND** ne jamais exécuter le contrôleur/service en aval.

### R2 — Refus fail-secure quand l'institution du porteur est introuvable
- **WHEN** une requête porte un bearer token dont l'utilisateur a un `institution_id`
  non nul **AND** `Institution::find(institution_id)` retourne `null` (institution
  supprimée / physiquement absente),
  **THE SYSTEM SHALL** refuser la requête avec **403** (même contrat que R1).
  *(Un porteur rattaché à une institution disparue ne doit jamais tourner non scopé.)*

### R3 — Préservation du chemin nominal (institution active)
- **WHILE** l'institution du porteur est `is_active=true`,
  **THE SYSTEM SHALL** poser le tenant et laisser la requête suivre son cours
  normal (aucune régression de statut, de latence perceptible, ni de payload).

### R4 — Préservation du supradmin (aucune institution)
- **WHEN** une requête porte un bearer token dont l'utilisateur a `institution_id = null`
  (supradmin / compte plateforme),
  **THE SYSTEM SHALL** laisser la requête suivre son cours **sans poser de tenant et
  sans refus** (le supradmin voit volontairement tous les tenants). Ce cas ne doit
  **jamais** être confondu avec R1/R2.

### R5 — Préservation de la voie non authentifiée
- **WHEN** une requête n'a pas de bearer token,
  **THE SYSTEM SHALL** conserver le comportement existant : header `X-Institution`
  absent → pas de tenant (routes publiques) ; header présent mais institution
  inactive/introuvable → **400** inchangé.

### R6 — Token invalide reste un 401
- **WHEN** une requête porte un bearer token invalide/expiré (aucun
  `PersonalAccessToken` correspondant, ou `tokenable` nul),
  **THE SYSTEM SHALL** laisser `auth:sanctum` produire le **401** habituel — le
  middleware ne doit **pas** transformer ce cas en 403.

### R7 — Décision documentée du trait `BelongsToInstitution`
- **WHERE** le tenant n'est pas résolu au moment d'une lecture globale-scopée,
  **THE SYSTEM SHALL** conserver le comportement **fail-open + `Log::warning`**
  existant (défense en profondeur), la garantie fail-secure étant portée par
  `ResolveInstitution` (R1/R2). La bascule vers un `throw` strict est **hors
  périmètre** (casserait les 3 flux légitimes sans-tenant : tests `actingAs`,
  contrôleurs à filtre explicite, jobs cross-tenant), tracée comme durcissement futur.

## 3. Critères d'acceptation (Definition of Done)

- [ ] Institution désactivée + bearer valide → **403** (voie bearer), prouvé par test.
- [ ] La même requête ne renvoie **aucune donnée d'un autre tenant** (test de fuite RED→GREEN).
- [ ] Supradmin (`institution_id` null) → **non refusé** (test anti-régression).
- [ ] Institution active → chemin nominal intact (test).
- [ ] Voie header inactive → **400** inchangé (test anti-régression).
- [ ] Token invalide → **401** inchangé (test anti-régression).
- [ ] Suite de non-régression LARGE verte (auth, tenant, plusieurs domaines).
- [ ] PHPStan 0 erreur ; fichiers ≤300 l ; méthodes ≤40 l ; DI strict.
- [ ] Specs `requirements/design/tasks` versionnées (`git add -f`).

## 4. Hors périmètre (explicite)

- Modifier les ~57 services ou les modèles `Institution`/`User`.
- Ajouter `SoftDeletes` à `Institution` (P0-4, #566/#567).
- Rendre `BelongsToInstitution` fail-closed par `throw` (durcissement global futur,
  interaction connue avec #567 sur la sémantique institution — à remonter à
  l'orchestrateur, pas à traiter ici).
