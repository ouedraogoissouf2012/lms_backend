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
### [PERF-02] N+1 HTTP KLASSCI → batch + caching
### [PERF-03] N+1 SQL → eager loading
### [PERF-04] Logique métier modèles → services
### [PERF-05] ClasseSyncService cleanup @temp.local
### [PERF-06] Jobs $tries/$timeout

---

## TIER 2 — TESTABILITÉ (4 PRs)

Après TIER 1 complété.

### [TEST-01] Tests désactivés → réactiver
### [TEST-02] ~~LMSDataController → tests feature~~ ✅ Largely OBE par PERF-01 (chaque controller extrait a ses propres tests Feature de routing : `tests/Feature/LMS/{Classes,Matieres,Enseignants,Notifications,Seances,Attendances,Visio}`). Resteraient à ajouter : tests d'intégration KLASSCI proxy (hors scope du split).
### [TEST-03] Multi-tenant isolation → tests
### [TEST-04] Exception handler → Sentry

---

## TIER 3 — OPS (4 PRs)

Après TIER 2 complété.

### [OPS-01] SQLite → MySQL/PostgreSQL
### [OPS-02] LOG_LEVEL debug → production-safe
### [OPS-03] Scheduler duplication → consolidate
### [OPS-04] TODOs actifs → implementer ou retirer

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
