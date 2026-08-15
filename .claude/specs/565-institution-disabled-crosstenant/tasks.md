# Tasks — #565 Institution désactivée → lecture cross-tenant

> Ordre TDD strict : tests RED d'abord, puis implémentation GREEN, puis audits.
> Chaque tâche référence un requirement (`_Requirements: Rx_`).

## 1. Tests RED (reproduire la fuite / cadrer le refus)
- [x] 1.1 Créer `tests/Feature/Middleware/ResolveInstitutionDisabledTenantTest.php` (7 tests, route sonde globale-scopée). _Requirements: R1,R2_
- [x] 1.2 Tests anti-régression : supradmin (R4), 401 token invalide (R6), 400 header (R5), scope nominal (R3).
- [x] 1.3 Lancer la classe → **RED confirmé** (3 échecs : 200 au lieu de 403, fuite reproduite).

## 2. Implémentation GREEN (`ResolveInstitution`)
- [x] 2.1 `resolveFromBearerToken(): ?Response` — refus 403 si `institution_id` set ET (introuvable OU inactive) ; `null` sinon. _Requirements: R1,R2,R3,R4,R6_
- [x] 2.2 Helper privé `deny(int, string): Response` (DRY 400/403, constantes `Response::HTTP_*`). _Requirements: R1,R5_
- [x] 2.3 `handle()` propage le refus bearer ; voie header réutilise `deny()`. _Requirements: R1,R5_
- [x] 2.4 Docblock de classe mis à jour (priorité 1 : fail-secure #565). _Requirements: R1_
- [x] 2.5 Lancer la classe → **GREEN (7/7)**.

## 3. Décision documentée du trait
- [x] 3.1 `BelongsToInstitution` docblock : garantie fail-secure au middleware, fail-open lecture conservé, durcissement `throw` = futur hors périmètre. Zéro changement de comportement. _Requirements: R7_

## 4. Validation LARGE (Phase 4)
- [x] 4.1 Non-régression : Feature **1126 tests / 0 échec**, Unit **450 / 0 échec** (hors segfault local). _Requirements: DoD_
- [x] 4.2 PHPStan level 9 : **0 erreur**, baseline intacte. _Requirements: DoD_
- [x] 4.3 Garde-fous : 148 l, méthodes ≤40 l, 0 `dd`/`var_dump`/`getMessage`. _Requirements: DoD_
- [x] 4.4 Audits read-only : `spec-security` **PASS** (0 HIGH), `spec-architect` **PASS** (0 HIGH). 2 retours LOW appliqués. `/thermo-nuclear-code-quality-review` indisponible → fallback production-grade-standards appliqué. _Requirements: DoD_

## 5. Livraison
- [ ] 5.1 `git add -f` des specs + tests. _Requirements: DoD_
- [ ] 5.2 Commit conventional (sujet ≤70) — **après accord user**. _Requirements: DoD_
- [ ] 5.3 PR vers `lms`, reporter le n° à l'orchestrateur, ne pas merger. _Requirements: DoD_

## Dette tracée (surfacée à l'orchestrateur — hors périmètre #565)
- **MEDIUM (archi)** : protection tenant-désactivé devient mono-couche (middleware). Un point d'entrée hors groupe `api` retomberait en fail-open. Durcissement fail-closed du trait = #566/#567 (~57 services).
- **LOW-1 (sécu)** : fail-open latent si un futur modèle devient `tokenable` sans `institution_id` (aujourd'hui seul `User` a `HasApiTokens` → non atteignable, pré-existant). Rattacher au chantier fail-closed.
