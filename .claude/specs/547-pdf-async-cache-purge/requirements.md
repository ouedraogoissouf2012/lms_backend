# Requirements — #547 : PDF DomPDF async par défaut + purge tenant réelle sur store `database`

> Sous-issue de #535 · P2 · labels `infrastructure`, `performance`
> Branche : `fix/547-pdf-async-cache-noop` · PR cible : `lms`

## Contexte (audit — racine, pas symptôme)

Deux défauts de performance/scalabilité coexistent, tous deux liés au **store cache
par défaut `database`** (`config/cache.php:18` → `env('CACHE_STORE', 'database')`,
figé aussi dans `phpunit.xml:48`) :

1. **PDF DomPDF synchrone par défaut.**
   `ReportController::generate*Report()` ne bascule en asynchrone que si le client
   passe `?async=1` ou l'en-tête `Prefer: respond-async`
   (`ReportController.php:51/66/81`, `wantsAsync():149`). Sans ce flag opt-in, le
   rendu Blade → DomPDF s'exécute **dans la requête HTTP** (`ReportGenerationService::renderPdf:174-183`),
   bloquant un worker FPM pour toute la durée du rendu — aggravé par
   `generateGrades:66-75` qui charge **toutes** les soumissions de la période en
   mémoire. Le chemin asynchrone complet existe déjà (job `GenerateReportPdf` sur la
   queue `low`, `AsyncReportDispatcher`, endpoints `asyncStatus`/`asyncDownload`,
   store `AsyncReportStore`) : **seul le défaut est mauvais.**

2. **Purge tenant no-op sur store `database`.**
   `TenantScopedCache::flushTenant()` (`TenantScopedCache.php:36-50`) délègue à
   `Cache::tags()->flush()`, or **les tags Laravel n'existent que sur Redis/Memcached**.
   Sur le store `database` (défaut prod), `supportsTags()` est `false` → `flushTenant()`
   est un **no-op journalisé**. La correction fonctionnelle (les données ne sont jamais
   servies périmées) est assurée par la **soft-invalidation** de
   `KlassciCacheKeyStrategy::invalidateTenant()` (bump du timestamp `invalidatedAt`,
   `KlassciCacheKeyStrategy.php:95-106`), driver-agnostic. **Mais** :
   - Les entrées `remember()` écrites AVANT un write portent l'ancien `invalidatedAt`
     dans leur clé. Après le bump, elles ne sont **plus jamais relues** (mismatch de
     clé) **ni supprimées** : elles restent dans la table `cache` jusqu'à expiration
     TTL paresseuse. Sur un tenant write-heavy, la table `cache` **accumule des lignes
     orphelines** → bloat, scans/locks dégradés (« lignes `cache` orphelines jamais GC »).
   - `flushTenant()` étant inerte, la purge physique post-write promise par
     `KlassciProxyService::invalidateCache():208-213` **ne libère jamais** cet espace.

## Objectif

- Basculer le **défaut** de génération de rapport PDF en **asynchrone** (queue `low`),
  en conservant un opt-out synchrone explicite pour les intégrations qui en dépendent.
- Rendre `flushTenant()` **réellement purgeur** sur le store `database` — purge
  **ciblée par tenant** (jamais un `Cache::flush()` cross-tenant, interdit
  CONTRIBUTING.md §E), afin d'éliminer les lignes orphelines.

## Glossaire

- **Namespace tenant** : préfixe de clé `institution_{id}` (ou `institution_none`)
  garantissant qu'une purge `LIKE '{prefix}%'` ne touche qu'un seul tenant.
- **Purge physique** : suppression réelle des lignes/clés (libère l'espace), par
  opposition à la **soft-invalidation** (les clés deviennent obsolètes mais restent).

---

## Requirement 1 — PDF asynchrone par défaut

**User story :** En tant qu'administrateur générant un rapport PDF volumineux, je veux
que la requête ne bloque pas un worker FPM, afin que l'API reste réactive sous charge.

#### Acceptance Criteria

1. WHEN un client `POST /api/admin/reports/{attendance|grades|activity}` **sans** flag
   de mode, THEN le système SHALL enfiler le job `GenerateReportPdf` sur la queue `low`
   et répondre `202 Accepted` avec `{id, status:'pending', status_url, download_url}`.
2. WHERE le client passe `?sync=1` OU l'en-tête `Prefer: respond-sync`, THE système
   SHALL générer le PDF en synchrone et le renvoyer en binaire (`200`, comportement
   historique préservé pour opt-out explicite).
3. WHERE le client passe l'ancien `?async=1` OU `Prefer: respond-async`, THE système
   SHALL continuer de répondre `202` (rétro-compatibilité : le flag opt-in reste
   accepté, il devient simplement redondant avec le défaut).
4. IF les flags `sync` et `async` sont tous deux présents, THEN le système SHALL
   traiter la requête en **asynchrone** (le défaut sûr l'emporte sur l'opt-out).
5. THE isolation tenant du job asynchrone (repose du `TenantManager` dans le worker,
   #536) SHALL rester inchangée et couverte par les tests existants.

---

## Requirement 2 — Purge tenant ciblée compatible store `database`

**User story :** En tant qu'exploitant, je veux que la purge post-write libère
réellement l'espace cache du tenant sur le store `database`, afin que la table `cache`
ne se remplisse pas de lignes orphelines.

#### Acceptance Criteria

1. WHEN `flushTenant()` est appelé ET que le store courant est `database`, THEN le
   système SHALL supprimer physiquement toutes les entrées cache du **tenant courant
   uniquement**, sans affecter les autres tenants ni la clé de soft-invalidation des
   autres tenants.
2. WHEN `flushTenant()` est appelé ET que le store supporte les tags (Redis/Memcached),
   THEN le système SHALL conserver le comportement actuel (`tags([tag])->flush()`).
3. IF le store ne supporte NI les tags NI une purge ciblée native (ex. `file`, `array`),
   THEN le système SHALL journaliser un no-op explicite et SHALL NOT exécuter de flush
   global de repli (CONTRIBUTING.md §E).
4. WHEN `remember()` mémorise une entrée sur un store sans tags, THEN la clé stockée
   SHALL être préfixée par le **namespace tenant**, afin que la purge ciblée
   (Requirement 2.1) puisse la retrouver par motif.
5. THE purge `database` SHALL être bornée au tenant via un motif de clé incluant le
   préfixe cache Laravel (`config('cache.prefix')`) ET le namespace tenant — jamais un
   `WHERE 1=1` ni un `TRUNCATE`.
6. THE sélection de la stratégie de purge (tags / database / no-op) SHALL être résolue
   par capacité du store, derrière une abstraction injectée (DIP §1.6 D), substituable
   par un fake en test (LSP §1.6 L).

---

## Requirement 3 — Isolation cross-tenant (non-régression sécurité)

#### Acceptance Criteria

1. WHEN le tenant A appelle `flushTenant()`, THEN les entrées cache du tenant B SHALL
   rester intactes (test explicite 2 institutions, PRODUCTION_STANDARDS §1.3).
2. WHEN le tenant courant est non résolu (`TenantManager::id() === null`), THEN la
   purge SHALL cibler le namespace `institution_none`, isolé des tenants réels.
3. THE motif de purge SHALL être insensible à toute donnée fournie par le client (le
   namespace dérive du `TenantManager` serveur, jamais d'un input requête).

---

## Requirement 4 — Contrats & qualité

#### Acceptance Criteria

1. THE interface `TenantScopedCacheInterface` publique SHALL rester inchangée
   (`remember`, `flushTenant`) — aucun consommateur métier n'est modifié.
2. THE réponse d'erreur de génération de rapport SHALL rester générique côté client
   (aucun `getMessage()` exposé, §1.2) — inchangé.
3. Chaque classe modifiée/créée SHALL rester ≤300 lignes, méthodes ≤40 lignes (§1.1, §5).
4. THE couverture SHALL inclure : défaut async, opt-out sync, priorité async>sync,
   purge database ciblée, isolation 2 tenants, no-op loggé store non supporté
   (happy path + edge cases, §1.3).
