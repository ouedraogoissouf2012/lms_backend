# Durcissement du cache local des séances KLASSCI — isolation tenant, DRY, god-method

> Épique GitHub : [#472 \[hardening\] Cache local des séances KLASSCI — isolation multi-tenant + dette architecture](https://github.com/ouedraogoissouf2012/lms_backend/issues/472)
> Sous-issues : [#473 (isolation multi-tenant, sécurité HIGH)](https://github.com/ouedraogoissouf2012/lms_backend/issues/473) · [#474 (duplication DRY, architecture HIGH)](https://github.com/ouedraogoissouf2012/lms_backend/issues/474) · [#475 (god method §5)](https://github.com/ouedraogoissouf2012/lms_backend/issues/475)
>
> **Nature de ce document : régularisation RÉTROACTIVE.** Le code est DÉJÀ implémenté, testé (60 tests verts) et poussé (PR #484, CI 9/10 verte). Ce `requirements.md` documente FIDÈLEMENT le code livré ; il ne spécifie pas une feature future. Chaque REQ ci-dessous décrit un comportement/une structure **déjà présent dans le dépôt** aux `fichier:ligne` cités.

## Contexte

Le job planifié `SyncKlassciSeances` (`app/Jobs/SyncKlassciSeances.php`) synchronise périodiquement les séances programmées de chaque enseignant depuis les backends KLASSCI vers un **cache local** (table `seances`). Ce cache alimente les vues calendrier/visio du LMS, les notifications de visioconférence et l'archivage des séances disparues côté KLASSCI.

Trois défauts ont été relevés sur ce chemin par l'audit projet et corrigés dans un même chantier :

1. **#473 — Isolation multi-tenant** (finding sécurité **HIGH**) : la clé `klassci_seance_id` portait une contrainte `unique()` **globale**, alors que deux institutions ont des backends KLASSCI **indépendants** pouvant réutiliser le même identifiant. Le job tournant **hors contexte HTTP**, le scope global `BelongsToInstitution` y est un **no-op délibéré** ; `institution_id` doit donc être écrit et scopé **explicitement**.
2. **#474 — Duplication DRY** (finding architecture **HIGH**) : le mapping payload KLASSCI → cache local (`localCacheData` / `updateLocalCacheData` / `parseSeanceStart`) était dupliqué byte-à-byte entre le job et `TeachingSeancesFetcher`.
3. **#475 — God method** (§5, ≤ 40 lignes/méthode) : `SyncKlassciSeances::handle()` faisait ~150 lignes (triple boucle imbriquée + archivage).

### Rappel invariant — pourquoi le scope global est inerte dans le job

Le trait `app/Models/Traits/BelongsToInstitution.php` applique une stratégie **« log-and-no-op »** (choix CRITICAL-06 du REFACTORING_ROADMAP, documenté au docblock `BelongsToInstitution.php:12-50`) : lorsqu'aucun tenant n'est résolu par le `TenantManager` — cas de **tout job / commande de fond** — le global scope de lecture **ne s'applique pas** (`BelongsToInstitution.php:69-79`) et le hook `creating` **n'auto-positionne pas** `institution_id` (`BelongsToInstitution.php:97-107`). C'est un choix **délibéré** (un `throw` casserait tests `actingAs`, contrôleurs déjà filtrés, et jobs cross-tenant légitimes), pas un bug. Corollaire direct : **dans le job, l'isolation par institution doit être portée par le code métier**, pas par le trait.

### Anti-patterns mesurés (avant correctif)

| Item | Valeur avant | Limite | Violation |
|------|--------------|--------|-----------|
| Contrainte d'unicité `klassci_seance_id` | `unique()` **global** (mono-tenant) | composite `(klassci_seance_id, institution_id)` | ❌ Collision / écrasement cross-tenant (sécurité HIGH) |
| `institution_id` écrit par le job | **implicite** (compté sur le trait, inerte hors HTTP) | explicite | ❌ Lignes créées `institution_id = NULL` |
| Lookup / archivage séances dans le job | **non scopés** par institution | scopés `withoutGlobalScope('institution')->where('institution_id', …)` | ❌ Archivage cross-tenant |
| Résolution classe / étudiants / enseignant (notif visio) | scope global (inerte en job) | scope explicite par institution | ❌ Notifications aux mauvais étudiants |
| Mapping payload → cache (`localCacheData`, `updateLocalCacheData`, `parseSeanceStart`) | **dupliqué** entre job et `TeachingSeancesFetcher` (~40 lignes) | 1 collaborateur unique (§1.1 DRY) | ❌ Divergence de mapping possible |
| `SyncKlassciSeances::handle()` | ~150 lignes (triple boucle + archivage) | 40 (§5) | ❌ +110 lignes |
| `SyncKlassciSeances.php` (fichier) | ~304 lignes | 300 (§1.1) | ❌ +4 lignes |
| `UpcomingSeancesFetcher.php` (fichier) | 346 lignes | 300 (§1.1) | ❌ +46 lignes |

### État après correctif (mesuré sur le code livré)

| Item | Valeur après | Conforme |
|------|--------------|----------|
| `app/Jobs/SyncKlassciSeances.php` | **66 lignes** ; `handle()` = **14 lignes** | ✓ §1.1 / §5 |
| `app/Services/Seances/Sync/KlassciSeancesSyncService.php` | 266 lignes ; toutes méthodes ≤ 40 | ✓ §1.1 / §5 |
| `app/Services/Seances/SeanceCacheDataBuilder.php` | 110 lignes | ✓ §1.1 |
| `app/Services/Seances/Sync/SeanceSyncStats.php` (DTO) | 44 lignes | ✓ §1.1 |
| `app/Services/Seances/ManagerSeancesLocalFetcher.php` | 131 lignes | ✓ §1.1 |
| `app/Services/Seances/UpcomingSeancesFetcher.php` | 246 lignes (était 346) | ✓ §1.1 |
| Migration `2026_07_20_000001_fix_seances_unique_per_institution.php` | backfill + unique composite | ✓ #473 |
| PHPStan level 9 | 0 erreur (22 entrées baseline obsolètes supprimées) | ✓ |
| Suite de tests | 60 tests verts (séances + services + jobs + notifications) | ✓ |

## Solution livrée (vue d'ensemble)

Suivre le pattern **MANIFESTE_REFACTORING.md** (orchestrateur fin + collaborateurs DI, cf. exemple `KlassciUserSynchronizer` → 3 collaborateurs) :

```
app/Jobs/SyncKlassciSeances.php                          (66 l.)  — orchestrateur fin, délègue à un service
app/Services/Seances/Sync/
├── KlassciSeancesSyncService.php                        (266 l.) — logique de sync + archivage, méthodes ≤40 l., isolation tenant explicite
└── SeanceSyncStats.php                                  (44 l.)  — DTO compteurs (remplace un array<string,int> passé par référence)
app/Services/Seances/
├── SeanceCacheDataBuilder.php                           (110 l.) — build() + applyTo(), mapping unique (#474), institution_id explicite
└── ManagerSeancesLocalFetcher.php                       (131 l.) — extrait de UpcomingSeancesFetcher (346→246), chemin manager cache local
database/migrations/
└── 2026_07_20_000001_fix_seances_unique_per_institution.php      — backfill NULL + unique composite (pattern fix_matieres/fix_classes)
app/Services/Notification/VisioNotificationDispatcher.php         — scope classe/étudiants/enseignant par institution (#473)
```

Chaque collaborateur : **DI constructor pur** (aucune Facade en code métier — §1.6 D), **méthodes ≤ 40 lignes**, **fichier ≤ 300 lignes**.

## Requirements (EARS)

### REQ-1 — Contrainte d'unicité composite par institution (#473)

WHERE `database/migrations/2026_07_20_000001_fix_seances_unique_per_institution.php` est appliquée,
THE migration SHALL :
1. **Backfiller** les séances existantes dont `institution_id IS NULL` et `created_by IS NOT NULL`, en déduisant `institution_id` depuis `users.institution_id` de leur créateur (`migration up() :30-37`). Justification : en SQL, plusieurs `NULL` restent permis dans un index unique — sans backfill, la contrainte composite ne protégerait pas l'historique.
2. **Remplacer** l'unique global `seances_klassci_seance_id_unique` par l'unique composite `seances_klassci_institution_unique` sur `(klassci_seance_id, institution_id)` (`migration up() :40-46`).

WHERE `down()` est exécutée,
THE migration SHALL restaurer exactement l'état antérieur (drop composite, recréation de l'unique global) — réversibilité stricte (`migration down() :49-55`).

THE choix de conception SHALL être **identique** aux migrations sœurs `fix_matieres_unique_per_institution` (#258) et `fix_classes_unique_per_institution` — même défaut, même remède (traçabilité inter-domaines).

### REQ-2 — Écriture explicite de `institution_id` dans le cache (#473)

WHERE `app/Services/Seances/SeanceCacheDataBuilder::build()` projette un payload KLASSCI,
THE méthode SHALL positionner `institution_id` **explicitement** depuis `$owner->institution_id` (`SeanceCacheDataBuilder.php:54`), et non compter sur le hook `creating` du trait.

WHEN le job `SyncKlassciSeances` s'exécute (hors contexte HTTP, scope global inerte),
THE cache local créé/mis à jour SHALL toujours porter un `institution_id` non nul correspondant au tenant de l'enseignant propriétaire.

### REQ-3 — Lookup et création de séance scopés par institution (#473)

WHERE `KlassciSeancesSyncService::upsertSeance()` recherche une séance locale existante,
THE requête SHALL être scopée explicitement : `Seance::withoutGlobalScope('institution')->where('institution_id', $institutionId)->where('klassci_seance_id', $klassciSeanceId)` (`KlassciSeancesSyncService.php:165-168`).

IF l'enseignant n'a pas de `institution_id` (donnée incohérente),
THEN le service SHALL **skipper défensivement** cet enseignant sans le synchroniser (`KlassciSeancesSyncService.php:90-93`), l'`institution_id` étant la clé de tenant.

WHEN une séance est créée,
THE service SHALL persister `institution_id` (via le cache builder), `visio_enabled=true`, `visio_type='jitsi'`, `visio_status='programmee'`, `visio_room_id` via `SecureVisioRoomIdGenerator::make()`, `visio_active=false`, `created_by=$teacher->id` (`KlassciSeancesSyncService.php:209-217`).

### REQ-4 — Archivage tenant par tenant (#473)

WHERE `KlassciSeancesSyncService::archiveStaleSeances()` archive les séances disparues de KLASSCI,
THE méthode SHALL parcourir un accumulateur `array<int $institutionId, array<int> $activeIds>` et n'archiver que **dans les institutions réellement synchronisées lors du run** (`KlassciSeancesSyncService.php:239-265`).

WHEN une séance active, rattachée à une institution, absente des `activeIds` de cette même institution est détectée,
THE service SHALL la marquer `is_active=false`, `archived_at=now()`, `archive_reason='supprimee_klassci'` et logger `{seance_id, klassci_seance_id, institution_id, matiere}` (`KlassciSeancesSyncService.php:250-262`).

THE archivage SHALL être **scopé** `withoutGlobalScope('institution')->where('institution_id', $institutionId)` — une séance d'un tenant B ne SHALL jamais être archivée parce qu'elle est absente du run d'un tenant A.

### REQ-5 — Notifications visio scopées par institution (#473)

WHERE `app/Services/Notification/VisioNotificationDispatcher` résout la classe cible d'une notification déclenchée depuis le job,
THE méthode `resolveClasse()` SHALL, quand `institution_id` est présent dans le payload, scoper `Classe::withoutGlobalScope('institution')->where('institution_id', $institutionId)` (`VisioNotificationDispatcher.php:226-236`). Justification : `klassci_id` de classe n'est pas unique entre tenants ; sans ce filtre, la classe d'une autre institution matcherait et de mauvais étudiants seraient notifiés.

WHERE `resolveTeacher()` résout l'enseignant à notifier,
THE méthode SHALL appliquer le même scope explicite par institution quand il est fourni (`VisioNotificationDispatcher.php:199-214`).

WHEN la notification est déclenchée en contexte HTTP (payload sans `institution_id`),
THE dispatcher SHALL laisser le scope global agir — le filtre explicite est alors ignoré (`VisioNotificationDispatcher.php:244-249`), préservant le comportement HTTP.

### REQ-6 — Collaborateur de mapping unique `SeanceCacheDataBuilder` (#474)

WHERE `app/Services/Seances/SeanceCacheDataBuilder.php` est créé,
THE classe SHALL éliminer la duplication de `localCacheData` / `updateLocalCacheData` / `parseSeanceStart` (dupliqués byte-à-byte entre `SyncKlassciSeances` et `TeachingSeancesFetcher`, ~40 lignes) en exposant :
1. `build(array $seance, array $matiere, User $owner): array` — **fonction pure** (aucune I/O) construisant le tableau de cache local (`SeanceCacheDataBuilder.php:42-64`).
2. `applyTo(Seance $seance, array $cacheData): void` — applique le cache à une entité existante, n'écrasant que les champs **non-null** (`null` KLASSCI = préserver l'existant) et ne persistant que si `isDirty()` (`SeanceCacheDataBuilder.php:73-84`).

WHERE `parseSeanceStart()` résout le datetime de début,
THE méthode SHALL retourner `Carbon::parse($heureDebut)` si présent et parsable, sinon `Carbon::parse($date)->startOfDay()`, sinon `null`, en avalant les `Throwable` de parsing sans propager (`SeanceCacheDataBuilder.php:90-109`).

THE collaborateur SHALL être **injecté par constructeur** partout où le mapping est requis (pattern MANIFESTE_REFACTORING.md), et non instancié inline.

### REQ-7 — Extraction du service de synchronisation (#475)

WHERE `app/Services/Seances/Sync/KlassciSeancesSyncService.php` est créé,
THE classe SHALL encapsuler l'intégralité de la logique auparavant dans `SyncKlassciSeances::handle()` (triple boucle enseignants → matières → séances + archivage), découpée en méthodes à responsabilité unique **toutes ≤ 40 lignes** : `sync()`, `syncTeacher()`, `syncMatiereSeances()`, `upsertSeance()`, `createSeance()`, `archiveStaleSeances()`.

THE constructor SHALL injecter, **sans aucune Facade** (§1.6 D) : `KlassciProxyService`, `ClasseSyncService`, `NotificationService`, `SeanceCacheDataBuilder`, `LoggerInterface` (PSR-3) (`KlassciSeancesSyncService.php:37-43`).

WHEN une erreur survient au niveau d'un enseignant ou d'une séance,
THE service SHALL la **capturer**, incrémenter `stats->errors`, logger le contexte, et **poursuivre** le run global sans interruption (`KlassciSeancesSyncService.php:101-107` et `:178-184`) — résilience préservée à l'identique de l'ancien job.

### REQ-8 — DTO de compteurs `SeanceSyncStats` (#475)

WHERE `app/Services/Seances/Sync/SeanceSyncStats.php` est créé,
THE DTO SHALL remplacer l'`array<string,int>` passé par référence à travers les méthodes (fragile, non typé) par un objet mutable typé exposant : `teachersChecked`, `seancesFound`, `seancesNew`, `notificationsSent`, `seancesArchived`, `errors` (`SeanceSyncStats.php:16-26`).

WHERE le logging de fin de run est produit,
THE DTO SHALL exposer `toArray(): array<string,int>` avec les **mêmes clés que l'historique du job** (`teachers_checked`, `seances_found`, …) pour préserver le format des logs structurés (`SeanceSyncStats.php:33-43`).

### REQ-9 — Job réduit à orchestrateur fin (#475)

WHERE `app/Jobs/SyncKlassciSeances.php` est refactoré,
THE fichier SHALL passer de ~304 à **66 lignes**, et `handle()` de ~150 à **14 lignes** (`SyncKlassciSeances.php:33-49`).

THE `handle()` SHALL se limiter à : logger le démarrage, appeler `$syncService->sync()`, logger `$stats->toArray()`, et re-`throw` toute exception fatale pour laisser jouer le retry (`SyncKlassciSeances.php:37-48`).

THE job SHALL préserver ses invariants de robustesse : `$tries=3`, `$timeout=600`, `$backoff=[60,300,900]`, et `failed()` résolvant `LoggerInterface` explicitement via `app()` (bordure framework hors container, pattern `AutoCloseEmptySeances` #209) (`SyncKlassciSeances.php:17-28`, `:54-65`).

THE dépendances `KlassciSeancesSyncService` et `LoggerInterface` SHALL être **injectées dans la signature de `handle()`** (résolution container), pas instanciées.

### REQ-10 — Extraction `ManagerSeancesLocalFetcher` (#475)

WHERE `app/Services/Seances/ManagerSeancesLocalFetcher.php` est créé par extraction de `UpcomingSeancesFetcher`,
THE `UpcomingSeancesFetcher.php` SHALL passer de 346 à **246 lignes** (§1.1 respecté), le chemin manager (lecture du cache local, projection legacy) étant une responsabilité distincte du walk KLASSCI étudiant/enseignant.

WHERE `ManagerSeancesLocalFetcher::fetch()` liste les séances,
THE méthode SHALL lire la table `seances` (`is_active=true`, `klassci_seance_id` et `date_seance` non nuls, dans la fenêtre demandée), filtrer optionnellement par enseignant et/ou classe, et projeter chaque ligne via `mapLocalSeance()` dans la forme legacy attendue par le frontend calendrier/visio (`ManagerSeancesLocalFetcher.php:34-130`).

### REQ-11 — Comportement runtime préservé (invariant transversal)

WHEN la suite de tests séances + services + jobs + notifications est exécutée,
THE suite SHALL passer **60 tests verts**, couvrant le comportement du job, du service de sync, du cache builder, de l'archivage et des notifications visio — le refactor étant **purement structurel** (aucun changement de comportement observable attendu).

WHERE l'isolation multi-tenant est vérifiée,
THE suite SHALL inclure des tests avec **2 institutions** (§1.3) démontrant l'absence de collision, d'archivage cross-tenant et de notification cross-tenant.

### REQ-12 — Conformité PRODUCTION_STANDARDS (invariant transversal)

WHERE le chantier est livré,
THE code SHALL satisfaire, vérifié par le guard CI :
1. Tous les fichiers touchés ≤ **300 lignes** (§1.1).
2. Toutes les méthodes ≤ **40 lignes** (§5).
3. **Aucune Facade** (`DB::`, `Log::`, `Http::`, `Hash::`) en code métier des collaborateurs — DI strict §1.6 D (les Facades subsistant sont des bordures framework documentées : `failed()` via `app()`, migration `DB::`).
4. **PHPStan level 9** = 0 erreur, avec suppression des **22 entrées de baseline devenues obsolètes** (pas d'ajout d'entrée).

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|------|----------------------|
| **N+1 SQL pré-existants dans `filterSeances()` / `enrichWithVisio()`** | Dette **tracée #476** (commit de référence #177). Défauts de performance **antérieurs** au chantier, sans lien avec l'isolation tenant / DRY / god-method. Correction séparée pour garder le focus et un diff auditable. |
| Refactor du walk KLASSCI de `UpcomingSeancesFetcher` au-delà de l'extraction du chemin manager | L'objectif #475 est de repasser le fichier sous 300 lignes ; une refonte plus profonde du walk est un chantier distinct. |
| Promotion du no-op `BelongsToInstitution` en `throw` strict | Décision CRITICAL-06 explicitement différée (docblock `BelongsToInstitution.php:46-51`) : nécessite que toutes les factories fournissent un `institution_id` par défaut et que tous les call-sites soient vérifiés. Issue de suivi distincte. |
| Migration du cache séances vers un autre store (Redis, table dénormalisée) | Décision orthogonale d'architecture, hors régularisation. |
| Backfill des séances dont `created_by IS NULL` (non rattachables à une institution) | La migration ne peut déduire leur tenant ; volume marginal et historique. À traiter par une opération data dédiée si nécessaire. |

## Critère d'acceptation global

La PR (#484) est mergeable WHEN :

1. ✓ REQ-1 à REQ-12 reflétés fidèlement par le code livré.
2. ✓ Migration `fix_seances_unique_per_institution` : backfill exécuté + unique composite `(klassci_seance_id, institution_id)` en place, `down()` réversible.
3. ✓ `SyncKlassciSeances.php` ≤ 66 lignes, `handle()` ≤ 14 lignes.
4. ✓ Tous les fichiers touchés ≤ 300 lignes ; toutes les méthodes ≤ 40 lignes (guard CI vert).
5. ✓ Aucune Facade en code métier des collaborateurs (`KlassciSeancesSyncService`, `SeanceCacheDataBuilder`, `VisioNotificationDispatcher`, `ManagerSeancesLocalFetcher`).
6. ✓ `SeanceCacheDataBuilder` est l'**unique** source du mapping payload → cache (aucune ré-implémentation de `localCacheData` / `parseSeanceStart` ailleurs).
7. ✓ Lookup, création et archivage de séances dans le job **scopés explicitement** par `institution_id`.
8. ✓ Notifications visio (`resolveClasse` / `resolveTeacher`) scopées par institution quand le payload le fournit.
9. ✓ 60 tests verts (séances + services + jobs + notifications), dont tests d'isolation à **2 institutions** (§1.3).
10. ✓ PHPStan level 9 = 0 erreur, 22 entrées baseline obsolètes supprimées (aucune ajoutée).
11. ✓ CI 9/10 verte (le job restant documenté comme non bloquant / pré-existant).
12. ✓ Épique #472 et sous-issues #473 / #474 / #475 fermées post-merge ; dette N+1 tracée sous #476.

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **`BelongsToInstitution` est promu en `throw` strict** (fin du no-op CRITICAL-06) — les scopes explicites `withoutGlobalScope('institution')->where('institution_id', …)` du job deviendraient redondants voire contre-productifs (double filtrage), et il faudrait résoudre un tenant courant pour le job plutôt que de le porter par argument.
2. **Le cache local des séances est supprimé** au profit d'un appel KLASSCI temps-réel systématique — `SyncKlassciSeances`, `KlassciSeancesSyncService`, `SeanceCacheDataBuilder` et la table `seances` deviendraient sans objet.
3. **KLASSCI garantit des `klassci_seance_id` globalement uniques** (espace d'ID partagé entre tenants) — l'unique composite `(klassci_seance_id, institution_id)` pourrait revenir à un unique global, et l'essentiel du durcissement #473 deviendrait inutile.
4. **Le job passe en exécution par-tenant** (un run isolé résolvant un tenant courant via `TenantManager`) — l'accumulateur `activeIdsByInstitution` et les scopes explicites seraient remplacés par le scope global redevenu actif.
5. **`TeachingSeancesFetcher` cesse de projeter des `Seance`** (bascule sur un DTO distinct) — la mutualisation via `SeanceCacheDataBuilder` (#474) perdrait sa raison d'être et la duplication ne serait plus un risque.

Aucune de ces 5 conditions n'est connue aujourd'hui.
