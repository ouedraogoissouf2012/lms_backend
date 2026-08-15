# Tasks — #516 [PERF][HIGH] CleanObsoleteSeances : requête non bornée + N+1 HTTP

## Révisé après `/code-review effort max` (8 agents finders + 1-vote verify)

L'implémentation initiale (tâches 1-7 ci-dessous) a fait l'objet d'une revue de
code qui a mis au jour un bug CRITIQUE introduit PENDANT le fix — pas dans le
code d'origine — plus 2 correctifs mineurs. Corrigés avant PR :

1. **CRITIQUE — régression cross-tenant réintroduite par le fix lui-même** :
   `SeanceExistenceBatchChecker` capturait `KlassciConfigResolver` UNE FOIS
   dans son constructeur ; `KlassciConfigResolver` mémorise `baseUrl`/`token`
   pour sa propre durée de vie (« singleton implicite par requête HTTP »,
   conçu pour 1 tenant). Comme `CleanObsoleteSeances::handle()` réutilise LE
   MÊME checker injecté pour TOUTES les institutions de la boucle, seule la
   1ère institution traitée obtenait sa vraie config — toutes les suivantes
   étaient silencieusement vérifiées contre le backend/token de la 1ère,
   reproduisant EXACTEMENT le bug cross-tenant que #516 corrige. Détecté
   indépendamment par 3 des 8 agents (cross-file tracer, efficiency,
   line-by-line). Corrigé : `SeanceExistenceBatchChecker` reçoit un
   `Container` (pas `KlassciConfigResolver` direct) et résout un résolveur
   FRAIS à CHAQUE appel de `checkMany()`. Test de régression dédié
   (`SeanceExistenceBatchCheckerTest::test_checkMany_resolves_fresh_config_on_each_call_not_memoized_from_first`)
   + `CleanObsoleteSeancesTenantIsolationTest` durci (`Http::preventStrayRequests()`
   + `Http::assertSent()` sur l'hôte réel) — les deux vérifiés RED sur le code
   fautif AVANT d'être GREEN sur le fix.
2. **Isolation de panne par institution absente** : l'ancien code isolait les
   pannes PAR SÉANCE (try/catch individuel) ; le refactor n'avait plus AUCUN
   try/catch — une institution en échec (config KLASSCI invalide, déchiffrement
   de token corrompu) aurait interrompu TOUT le job, y compris les institutions
   saines restantes, et sauté `TenantManager::reset()`. Corrigé : try/catch
   PAR institution (`processInstitution()`) + `try/finally` autour de la boucle
   garantissant `TenantManager::reset()` (pattern déjà établi dans
   `app/Jobs/GenerateReportPdf.php` #536) + `reset()` en DÉBUT de `handle()`
   (purge un tenant résiduel avant la requête cross-tenant initiale). Test :
   `CleanObsoleteSeancesTest::test_institution_with_malformed_klassci_url_is_isolated_others_still_processed`.
3. **N+1 SQL ironique** : `Institution::find()` appelé DANS la boucle
   (1 requête par institution) — le job qui élimine le N+1 HTTP réintroduisait
   un N+1 SQL. Corrigé : préchargement `Institution::whereIn('id', ...)->get()->keyBy('id')`
   en 1 requête avant la boucle.

Findings évalués mais NON corrigés (documentés, pas des bugs) : duplication du
bloc de parsing de config pool/timeout avec `KlassciBatchFetcher` (fichier hors
périmètre, non touché — dédoublonné localement dans `SeanceExistenceBatchChecker`
uniquement via `positiveIntConfig()`) ; `array &$stats` threadé par référence
sur plusieurs méthodes (style, jugé acceptable — testé et fonctionnel) ;
`array_unique()` redondant dans `checkMany()` (défense en profondeur
volontaire, coût négligeable).

## Révisé après audits `spec-security` (PASS) + `spec-architect` (FAIL → corrigé)

`spec-security` : **PASS**, 0 finding bloquant, 1 LOW informatif (dépendance
Auth-guard dans `KlassciConfigResolver` — hors périmètre, tracée pour l'épique
Octane #378, sans impact sur l'infra actuelle sans Octane).

`spec-architect` : **FAIL** initial (1 HIGH, 2 MEDIUM), corrigé avant PR :

1. **HIGH — N+1 SQL EN ÉCRITURE** : `archive()` faisait un `$seance->save()`
   PAR séance confirmée supprimée (jusqu'à `drainChunkSize`=200 `UPDATE` par
   lot) — le job qui élimine le N+1 HTTP réintroduisait un N+1 SQL en
   écriture. Corrigé : `checkAndArchiveBatch()` collecte les IDs
   `ConfirmedDeleted` puis `archiveMany()` fait 1 seul
   `Seance::whereIn('id', $ids)->update(['is_active' => false])`. Test dédié
   (`CleanObsoleteSeancesTest::test_archiving_multiple_confirmed_deleted_seances_uses_one_bulk_update_not_one_per_seance`,
   `DB::enableQueryLog()`) — vérifié RED (10 UPDATE) sur l'ancien code AVANT
   d'être GREEN (1 UPDATE) sur le fix.
2. **MEDIUM — service locator via `Container::make()`** : le `Container`
   injecté dans `SeanceExistenceBatchChecker` (fix précédent pour la
   staleness, cf. section ci-dessus) était lui-même un anti-pattern —
   dépendance cachée dans le corps de `checkMany()`, classe non mockable par
   simple injection constructeur. L'audit a identifié la cause racine :
   `KlassciConfigResolver` est le mauvais outil dans un contexte de Job
   (priorités 1/2 mortes, priorité 3 = `Institution::getKlassciConfig()` =
   exactement les propriétés déjà lues par le Job). Corrigé en profondeur :
   `checkMany(array $ids, string $baseUrl, ?string $token)` — `baseUrl`/
   `token` en paramètres explicites, `Container` ET `KlassciConfigResolver`
   entièrement supprimés de `SeanceExistenceBatchChecker`. Effet de bord
   positif : les tests unitaires n'ont plus besoin de lier des instances
   dans le vrai conteneur Laravel (mock constructeur trivial).
3. **MEDIUM — duplication du helper pool-request-building avec `KlassciBatchFetcher`**
   — non corrigé : `KlassciBatchFetcher.php` est hors périmètre de fichiers
   pour #516 (non modifiable). Tracé comme issue de suivi (extraire un helper
   statique commun sur `KlassciHttpClient`).
4. LOW (informationnel, aucune action) : `handle()` à 41 lignes brutes vs le
   plafond de 40 — corps de code réel ~23 lignes une fois commentaires/lignes
   vides exclus, jugé non problématique par l'audit lui-même.

- [x] 1. Test RED : isolation cross-tenant
  - 2 institutions, 2 `klassci_api_url` distinctes mockées. Séance de
    l'institution B vérifiée avec la config globale (comportement actuel) →
    archivée à tort. Doit échouer AVANT le fix (c'est le comportement
    actuel, prouvé buggé).
  - _Requirements: R1_

- [x] 2. GREEN : `SeanceExistenceBatchChecker` + enum `SeanceCheckResult`
  - Nouveau fichier `app/Services/Seances/Sync/SeanceExistenceBatchChecker.php`
    + `app/Services/Seances/Sync/SeanceCheckResult.php` (enum).
  - Pool HTTP direct (pattern `KlassciBatchFetcher::buildPoolRequests`),
    classification 3 états (exists/confirmed_deleted/error) par code HTTP réel,
    pas par `str_contains()` sur un message d'exception.
  - _Requirements: R3, R4_

- [x] 3. GREEN : refactor `CleanObsoleteSeances::handle()`
  - Grouper par `institution_id`, `TenantManager::set($institution)` par
    groupe, skip propre si config KLASSCI absente (R2), `chunkById` +
    budget-temps préservés par institution (R5), archive uniquement sur
    `SeanceCheckResult::ConfirmedDeleted`.
  - `TenantManager::reset()` en fin de job (jamais de tenant résiduel).
  - _Requirements: R1, R2, R5_

- [x] 4. Test anti-N+1 (baseline-vs-afterGrowth)
  - Assertion sur le nombre d'appels pool vs séquentiels ; 3 séances vs 30
    séances pour la même institution → pas de croissance linéaire du nombre
    d'appels réseau distincts (regroupés en lots de taille fixe).
  - _Requirements: R3_

- [x] 5. Test distinction 404 vs erreur
  - Un ID → 404 (archivé) ; un ID → 500/timeout (PAS archivé, compté à part).
    Doit échouer si `SeanceCheckResult::Error` archive par erreur.
  - _Requirements: R4_

- [x] 6. Test institution sans config KLASSCI
  - Institution avec `klassci_api_url`/`klassci_api_token` null → ses séances
    ignorées ce run, warning loggé, les AUTRES institutions traitées quand
    même (pas d'arrêt du job entier).
  - _Requirements: R2_

- [x] 7. Test budget-temps par institution (non-régression #539)
  - Adapté de `DrainBudgetTest` : budget bas → institutions restantes
    reportées, job idempotent, pas d'archivage prématuré.
  - _Requirements: R5_

- [x] 8. Non-régression : suite Seances/Jobs complète + PHPStan (scope `app/`)
      + garde-fou taille.

- [x] 9. `/code-review effort max` (fallback confirmé, `/thermo-nuclear-code-quality-review`
      indisponible) — corriger tout ce qui remonte.

- [x] 10. Audits `spec-security` + `spec-architect` en parallèle (CONTRIBUTING.md §A)
      — porter une attention particulière à l'isolation multi-tenant (R1),
      c'est le cœur du correctif.

- [ ] 11. PR vers `lms`, reporter le numéro à l'orchestrateur — mentionner
      explicitement la découverte du défaut racine `KlassciConfigResolver`
      (hors périmètre, affecte potentiellement #515/PR #561) comme
      recommandation d'issue de suivi séparée.
