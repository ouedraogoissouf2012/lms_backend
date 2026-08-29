# Requirements — #567 : `InstitutionCrudService::softDelete()` en vraie suppression logique

> Sous-issue P0 de #563 (audit 2026-08-15). Version détaillée : #572.
> Format EARS. Approbation requise avant `design.md`.

## Contexte

`app/Services/Institution/InstitutionCrudService.php:116-125` : la méthode `softDelete()`
appelle `$institution->delete()` sur un modèle **sans** trait `SoftDeletes` et sur une table
**sans** colonne `deleted_at`. C'est donc une **suppression physique** : le nom de la méthode et
le message client (« Institution supprimée avec succès ») mentent.

Conséquences (#572) : perte irréversible de la config du tenant (URL/token KLASSCI chiffré,
branding, `settings`) ; orphelins massifs (`institution_id` sans FK) ; amplification de la fuite
cross-tenant (un compte orphelin → tenant non résolu → scope désactivé) ; aucun garde-fou
symétrique à `toggleActive()`.

## Exigences

### R1 — La suppression d'institution est logique, pas physique
- **WHEN** `softDelete($id)` est appelé, le système **SHALL** effectuer un *soft delete*
  (`deleted_at` renseigné) et **SHALL** conserver la ligne `institutions`.
- La suppression **SHALL** être réversible (`restore()` possible).

### R2 — Une suppression n'est jamais un premier geste
- **IF** l'institution est encore active (`is_active = true`), le système **SHALL** refuser la
  suppression avec une `BusinessException` mappée en **HTTP 422** (message explicite).
- **WHEN** l'institution est déjà désactivée (`is_active = false`), le système **SHALL** autoriser
  le soft delete.
- _Note_ : exiger `is_active = false` subsume le garde-fou « ne pas supprimer la dernière active »
  de `toggleActive()` — une institution active ne peut jamais être supprimée, a fortiori la
  dernière active. `toggleActive()` garantit déjà qu'on ne peut pas désactiver la dernière active.

### R3 — La suppression coupe l'accès des utilisateurs du tenant (fail-safe autonome)
- **WHEN** une institution est soft-deletée, le système **SHALL** révoquer tous les jetons Sanctum
  des utilisateurs de cette institution → requête suivante `401`.
- **Justification (coordination #565↔#567)** : `ResolveInstitution` résout le tenant à `null` pour
  une institution soft-deletée → sans le fail-closed de #565, le scope serait sauté (fail-open
  cross-tenant). La révocation ferme cette fenêtre **indépendamment de #565**, qui reste la défense
  en profondeur. Le login est en outre impossible (R5 : la discovery exclut l'institution).

### R4 — Traçabilité de la suppression d'un tenant
- **WHEN** une institution est soft-deletée, le système **SHALL** écrire une entrée `audit_logs`
  (`institution.soft_deleted`) mentionnant l'acteur, la cible et le nombre de sessions révoquées.

### R5 — Les lectures d'institution ignorent les supprimées
- **WHEN** la découverte de tenants au login (`KlassciTenantDiscovery::loadActiveTenants`) ou la
  liste/overview admin (`InstitutionQueryService`) s'exécute, le système **SHALL** exclure les
  institutions soft-deletées (comportement natif du `SoftDeletingScope` ; caches déjà invalidés par
  `invalidateCaches()`).

### R6 — Purge définitive délibérée, sans orphelins
- **WHERE** une purge physique est nécessaire, le système **SHALL** l'exposer via une commande
  console **dry-run par défaut**.
- **IF** l'institution soft-deletée possède encore des lignes filles (utilisateurs), le système
  **SHALL** refuser la purge (`institution_id` n'a pas de FK — un `forceDelete` créerait des
  orphelins). La purge **SHALL** être journalisée.

## Hors périmètre (déclaré)

- `BelongsToInstitution` / `ResolveInstitution` (sous-issue #565). #567 ne les modifie pas ; R3
  ferme la fenêtre par révocation, #565 apporte le fail-closed général.
- Création des FK `institution_id` (autre sous-issue #563 — « FK manquantes »). R6 compense par un
  refus de purge tant que des filles existent.
- Endpoint de restauration (non requis) : le modèle expose `restore()`, testé.

## Critères d'acceptation (traçant #572)

- [ ] Test : suppression d'une institution **active** → refusée `422`, message explicite. _(R2)_
- [ ] Test : suppression d'une institution **désactivée** → soft delete, la ligne existe en base. _(R1)_
- [ ] Test : après suppression, un utilisateur de l'institution ne peut plus lire (jeton révoqué → `401`). _(R3)_
- [ ] Test : la découverte de tenants au login ignore les institutions supprimées. _(R5)_
- [ ] Test : entrée `audit_logs` vérifiée. _(R4)_
- [ ] Test : `restore()` ramène l'institution. _(R1)_
- [ ] Test : purge dry-run inerte ; `--force` refuse si des utilisateurs subsistent, purge sinon. _(R6)_
- [ ] `php artisan test` 100 %, PHPStan niveau 9 vert, `migrate:fresh` à blanc OK.
