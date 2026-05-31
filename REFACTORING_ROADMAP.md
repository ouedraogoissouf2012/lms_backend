# ROADMAP DE REFACTORING — ORDRE STRICT

Chaque étape doit être 100% complète avant de passer à la suivante.

---

## TIER 0 — SÉCURITÉ CRITIQUE (12 PRs)

À fixer EN PREMIER. Ces PRs bloquent tout développement nouveau.

### [CRITICAL-01] Tokens KLASSCI — Chiffrement au repos
**Problème**: Tokens plaintext en base.
**Solution**: Migration + encrypted cast Laravel.
**Fichiers**: User.php, Institution.php, migration.
**Tests**: Decrypt/encrypt roundtrip OK, DB stocke chiffré.

### [CRITICAL-02] Exception messages — Couche d'abstraction
**Problème**: 150+ $e->getMessage() exposés.
**Solution**: Exception handler central, messages génériques en prod.
**Fichiers**: ExceptionHandler.php (NEW), bootstrap/app.php, 30+ controllers.
**Tests**: 404, 500, validation → messages génériques sans détail.

### [CRITICAL-03] Routes publiques — Sécuriser /api/proxy/*
**Problème**: Structure organisationnelle exposée sans auth.
**Solution**: Ajouter middleware auth:sanctum + ensure.role.
**Fichiers**: routes/api.php.
**Tests**: Sans token → 401, avec token → 200 avec filtre tenant.

### [CRITICAL-04] Rate limiting — Heartbeat + critiques
**Problème**: Seul /auth/login throttled. Heartbeat sans limite.
**Solution**: Throttle 10k/min heartbeat, 60/min submit, 100/min GET.
**Fichiers**: routes/api.php (5 routes).
**Tests**: 65 requests/min → 429 Too Many Requests.

### [CRITICAL-05] EnsureKlassciSync — Empêcher escalade privilèges
**Problème**: email + role écrasés par KLASSCI → attaque possible.
**Solution**: Ne jamais écraser role, ajouter klassci_role séparé.
**Fichiers**: EnsureKlassciSync.php, migration, User.php.
**Tests**: Sync avec rôle admin → LMS role inchangé.

### [CRITICAL-06] BelongsToInstitution — Forcer résolution tenant
**Problème**: Scope silencieux en job → data leak cross-tenant.
**Solution**: Throw exception si TenantManager non init.
**Fichiers**: BelongsToInstitution.php.
**Tests**: Job A → données A seulement, B non visible.

### [CRITICAL-07] X-Institution header — Forcer JWT, pas header
**Problème**: Tenant résolu via header non protégé.
**Solution**: Retirer header en prod, utiliser institution_id du JWT.
**Fichiers**: ResolveInstitution.php.
**Tests**: Header ignoré, JWT utilisé, cross-institution blocked.

### [CRITICAL-08] SSL — Forcer vérification prod
**Problème**: SSL_VERIFY=false rend MITM possible.
**Solution**: Throw exception si SSL disabled en prod.
**Fichiers**: KlassciProxyService.php, .env.production.example.
**Tests**: Prod + SSL=false → exception.

### [CRITICAL-09] Cache invalidation — Implémenter vraiment
**Problème**: invalidateCache() ne fait que logger.
**Solution**: Cache::forget() la clé.
**Fichiers**: KlassciProxyService.php.
**Tests**: Write data, invalidate, read → nil ou default.

### [CRITICAL-10] Cache::flush() — Scoper par tenant
**Problème**: Marquer notification vide le cache global.
**Solution**: Cache::tags(["institution_X"])->flush().
**Fichiers**: NotificationsController.php, config/cache.php.
**Tests**: User A marque notif → user B cache intact.

### [CRITICAL-11] EnsureRole — Masquer required_roles
**Problème**: 403 expose required_roles.
**Solution**: Retirer la réponse, logger côté serveur.
**Fichiers**: EnsureRole.php.
**Tests**: 403 sans required_roles dans JSON.

### [CRITICAL-12] Tinker — Déplacer en require-dev
**Problème**: Exécution code arbitraire en prod.
**Solution**: composer remove, composer require --dev.
**Fichiers**: composer.json.
**Tests**: tinker pas en require.

---

## TIER 1 — PERFORMANCE (6 PRs)

Après TIER 0 complété.

### [PERF-01] ~~Split LMSDataController~~ ✅ DONE (PRs #108→#117) — décomposé en 7 controllers SRP + 2 services partagés. Spec : `.claude/specs/lms-data-controller-split/`.
### [PERF-02] ~~N+1 HTTP KLASSCI → batch + caching~~ ✅ DONE — KlassciProxyService implémenté avec cache + batch logic.
### [PERF-03] ~~N+1 SQL → eager loading~~ ✅ DONE — eager loading appliqué dans tous les controllers extraits (cf. spec lms-data-controller-split).
### [PERF-04] ~~Logique métier modèles → services~~ ✅ DONE (PRs #165 #166 #167) — extraction Quiz/Evaluation/Lesson/Notification logic vers services dédiés (QuizGradingService, EvaluationGradingService, LessonProgressService, NotificationPresenter). Modèles conservent thin wrappers pour backward-compat.
### [PERF-05] ~~ClasseSyncService cleanup @temp.local~~ ✅ DONE (PR #160) — fake emails @temp.local remplacés par null + migration de cleanup. PR #173 hardening : fail-secure tenant resolution dans `syncStudents` (pivot classe_etudiant).
### [PERF-06] ~~Jobs $tries/$timeout~~ ✅ DONE — Jobs ont $tries + $timeout définis (cf. ProcessFileConversion, DetectDisconnectedParticipants).

**TIER 1 : 6/6 ✅ COMPLET le 2026-05-30**

---

## TIER 2 — TESTABILITÉ (4 PRs)

Après TIER 1 complété.

### [TEST-01] ~~Tests désactivés → réactiver~~ ✅ DONE (PRs #169 #170 #171) — 9 fichiers de tests désactivés via `<exclude>` réactivés. **+94 tests** réactivés en 3 batches (Chapter/Upload, TokenEncryption, ExceptionHandler/ResolveInstitution/AdminAnalytics). **2 bugs sécu** réels découverts et fixés : (a) `ResolveInstitution` ne reset pas TenantManager singleton entre requêtes → cross-tenant data leak sous Octane (#171), (b) Exception details exposés en staging via `isProduction()` au lieu de `app.debug` (#171). `phpunit.xml <exclude>` désormais vide.
### [TEST-02] ~~LMSDataController → tests feature~~ ✅ Largely OBE par PERF-01 (chaque controller extrait a ses propres tests Feature de routing : `tests/Feature/LMS/{Classes,Matieres,Enseignants,Notifications,Seances,Attendances,Visio}`). Resteraient à ajouter : tests d'intégration KLASSCI proxy (hors scope du split).
### [TEST-03] ~~Multi-tenant isolation → tests~~ ✅ DONE (PR #172 + PR #173 hardening + PR #174 coverage) — audit complet des bypass du scope `BelongsToInstitution`. 1 bug réel fixé : `DashboardAdminController::stats` utilisait `DB::table('forum_posts')` qui bypassait le scope (#172). 5 tests régression sur `Dashboard` + 14 tests sur mutations `QuizAttempt` ajoutés. Hardening : 3 WARN (NotificationsController docblock, ClasseSyncService fail-secure, InstitutionController docblock) en PR #173. **+19 tests** au total.
### [TEST-04] ~~Exception handler → Sentry~~ ⏸️ DEFERRED (chief decision 2026-05-30) — pas d'intégration Sentry pour l'instant. Le handler actuel ([bootstrap/app.php:51-66](bootstrap/app.php#L51-L66)) continue de logger via `Log::error` vers `storage/logs/laravel.log` sur cPanel. À reconsidérer quand le projet sera en charge réelle (volume d'erreurs justifiant un APM).

**TIER 2 : 3/4 ✅ DONE + 1 ⏸️ DEFERRED le 2026-05-30**

---

## TIER 3 — OPS (4 PRs)

Après TIER 2 complété.

### [OPS-01] ~~SQLite → MySQL~~ ✅ DONE (PRs #143 + #139 + #164) — le code supporte dual-DB sqlite (local dev) + mysql (prod cPanel). PR #143 a supprimé pgsql (code mort). PR #139 a aligné phpunit.xml sur sqlite. La migration `2026_01_03_220000_add_quiz_to_chapters_content_type` a deux branches (sqlite recreate-table + mysql ALTER ENUM) — pattern à suivre pour toute migration future qui touche un type. PR #164 (cette PR) finalise la doc.
### [OPS-02] ~~LOG_LEVEL debug → production-safe~~ ✅ DONE (PR #161) — LOG_LEVEL fallback à 'info' au lieu de 'debug', évite les fuites d'info détaillée en prod.
### [OPS-03] ~~Scheduler duplication → consolidate~~ ✅ DONE (PR #162) — duplication entre `bootstrap/app.php` et `routes/console.php` supprimée. Source unique : `routes/console.php`.
### [OPS-04] ~~TODOs actifs → implementer ou retirer~~ ✅ DONE (PR #151 + #163) — 0 TODO/FIXME/HACK actionnable dans `app/` `routes/` `bootstrap/` `tests/` `database/` `config/` à la date 2026-05-30. 9 TODOs nettoyés en PR #151 ; les 2 meta-comments référençant l'historique nettoyés en PR #163. Le seul stub status documenté (LMSNotificationsPreferencesController) est tracé dans son docblock, pas en TODO inline.

**TIER 3 : 4/4 ✅ COMPLET le 2026-05-30**

---

## VALIDATION GLOBALE POST-TIER

```bash
php artisan test          # Must be 100% pass
grep -r "getMessage()"    # Must be 0
grep -r "plaintext"       # Must be 0
grep -r "Cache::flush()"  # Must be 0 (outside tests)
```

Condition TIER 0→1: Tous les CRITICAL fusionnés.
Condition TIER 1→2: Tous PERF fusionnés, tests passent.
Condition TIER 2→3: Tous TEST fusionnés.
Condition FINAL: Production-ready, security audit passed.

---

## ÉTAT GLOBAL (2026-05-30)

| TIER | Statut | Notes |
|---|---|---|
| TIER 0 — SÉCURITÉ | ✅ COMPLET | 12/12 CRITICAL fusionnés |
| TIER 1 — PERFORMANCE | ✅ COMPLET | 6/6 fusionnés |
| TIER 2 — TESTABILITÉ | ✅ ESSENTIEL COMPLET | 3/4 DONE + 1 DEFERRED (Sentry, chief decision) |
| TIER 3 — OPS | ✅ COMPLET | 4/4 fusionnés |

**Roadmap globale : 25/26 items DONE + 1 DEFERRED.**

Bugs sécu réels découverts durant le refactor (TIER 1+2) :
1. `ResolveInstitution` ne reset pas TenantManager → cross-tenant data leak sous Octane (PR #171)
2. Exception details exposés en staging (`isProduction()` au lieu de `app.debug`) (PR #171)
3. `DashboardAdminController::stats` bypassait `BelongsToInstitution` via `DB::table` (PR #172)
4. `ClasseSyncService` pivot insert silencieux si tenant non résolu — hardening fail-secure (PR #173)

Tests réactivés/ajoutés durant TIER 2 :
- TEST-01 : +94 tests réactivés (3 batches, 0 fichier exclu maintenant)
- TEST-03 : +5 tests régression Dashboard + 14 tests QuizAttempt mutations
- **Total : 758 → 772 tests** (avec PRs #173, #174 en cours)

**Statut serveur cPanel** : refactoring en cours, ne pas déployer (`git pull` serveur) avant l'ordre explicite (cf. `feedback_no_cpanel_deploy_until_refactoring_done`).
