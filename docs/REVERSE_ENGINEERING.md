# Rétro-ingénierie — LMS Backend (KLASSCI)

> Document produit par analyse exhaustive du code réel (2026-07-02, branche `lms`).
> Toutes les affirmations sont sourcées `fichier:ligne`. Les affirmations de conformité
> aux standards internes ont été **vérifiées sur le code**, pas reprises de la roadmap.
>
> **Suivi** : chaque dette du §4.2 est trackée par une issue GitHub (#362→#377, #401) ;
> le tableau de statut en tête du §4.2 fait foi (mis à jour au 2026-07-03). Les
> `fichier:ligne` cités photographient l'état au 2026-07-02 — l'historique git fait foi
> pour les fichiers déplacés depuis (ex. split de `routes/api.php` en `routes/api/*.php`).

---

## 1. Logique globale et intention d'origine

### 1.1 Ce qu'est ce système

Le LMS est un **satellite pédagogique multi-tenant d'un ERP scolaire externe nommé KLASSCI**.
KLASSCI est la **source de vérité amont** pour : identités, rôles, classes, matières,
enseignants, filières, emplois du temps, évaluations officielles et fenêtres de passage.
Le LMS ajoute par-dessus une couche pédagogique propre : leçons/chapitres avec progression
séquentielle, quiz natifs, mini-quiz de chapitre (« KnowledgeCheck »), forum, notifications
in-app, et surtout **séances de visioconférence (Jitsi) avec suivi de présence par heartbeat**.

Une seule instance sert plusieurs établissements (**institutions**), chacun avec sa propre
URL/token KLASSCI (`institutions.klassci_api_url` / `klassci_api_token_encrypted`,
cast `encrypted` — `app/Models/Institution.php:26`).

### 1.2 Intention du développeur (lisible dans le code)

Le code révèle un projet **né MVP puis industrialisé méthodiquement** (roadmap
`REFACTORING_ROADMAP.md`, PRs #176→#337 référencées dans les PHPDoc). Quatre obsessions
structurent l'architecture, par ordre de priorité apparente :

1. **Isolation multi-tenant en défense en profondeur** — middleware `ResolveInstitution`
   (prepend global, `bootstrap/app.php:34`), global scope `BelongsToInstitution`, résolution
   du tenant **exclusivement depuis le token Bearer** (le header `X-Institution` est ignoré
   quand un token est présent — anti-usurpation, `app/Http/Middleware/ResolveInstitution.php:52-56`),
   clés de cache scopées par slug fail-secure (`TenantManager::getResolvedSlug()` throw si
   tenant non résolu).
2. **Méfiance envers KLASSCI** — le LMS ne fait *jamais* confiance à l'ERP pour
   l'autorisation : `users.role` est figé après création (CRITICAL-05), `email` jamais
   re-synchronisé (anti-hijack de reset password), toute divergence de rôle est loggée avec
   flag `is_escalation_attempt` (`app/Http/Middleware/EnsureKlassciSync.php:121-153`).
   Défense explicite contre un ERP compromis.
3. **Résilience de l'intégration externe** — panne KLASSCI → 503 retryable
   (`KlassciUnavailableException`, `bootstrap/app.php`), login multi-tenant en `Http::pool`
   parallèle tolérant aux pannes partielles, cache 3 couches (memo intra-requête →
   cache distribué avec soft-invalidation par timestamp tenant → HTTP), jobs idempotents
   avec `tries`/`backoff`/`failed()`.
4. **Conformité mécanique à des standards internes stricts** (`PRODUCTION_STANDARDS.md`) —
   limite 300 lignes/fichier appliquée par un check CI (`file-size-guard`), DI par contrats
   PSR (zéro Facade en code métier — vérifié), 130 FormRequests, enveloppe JSON unique
   via le trait `RespondsWithJson`.

### 1.3 Architecture en couches

```
routes/api.php (174 routes, montées sur /api ET /api/v1 ; /api/v2 réservé, vide)
  → ResolveInstitution (global, prepend : résout le tenant, reset anti-Octane)
  → auth:sanctum (tokens 7 j, PersonalAccessToken custom sans scope tenant)
  → klassci.sync (re-sync passif 24 h, invariants rôle/email verrouillés)
  → role:... (EnsureRole : hiérarchie supradmin > superadmin > rôles métier)
  → throttle granulaire (10/min login … 300/min lecture, limiteurs proxy nommés)
  → Controllers fins (52) → Services SRP (128 fichiers, 24 dossiers) → Modèles (33)
```

- **Pas d'API Resources** : sérialisation par Presenters + trait d'enveloppe
  `{success, message?, data?, meta?}` (`app/Http/Controllers/Concerns/RespondsWithJson.php`).
- **Erreurs** : handler JSON centralisé dans `bootstrap/app.php:58-131`
  (401/422/429/503/500, détail masqué hors debug).
- **Pattern service récurrent** : retour `array{status:int, ...payload}` mappé en HTTP
  par le controller ; un orchestrateur racine délègue à des collaborateurs dans un
  sous-dossier (ex. `KlassciProxyService` + 5 collaborateurs, `NotificationService`
  + 5 dispatchers, `ClasseSyncService` + fetcher/synchronizer).

### 1.4 Flux d'authentification (le plus révélateur de l'intention)

`AuthController::login` (`app/Http/Controllers/API/AuthController.php`) :
1. **Tentative locale** (`LocalLmsAuthenticator`) — sert le supradmin (inexistant dans
   KLASSCI, `institution_id = NULL`) et le fast-path. Les comptes issus de KLASSCI ont un
   password local inutilisable (`Hash::make(uniqid())`) qui force l'étape 2.
2. **Découverte multi-tenant** (`KlassciTenantDiscovery`) — interroge `/auth/check-user`
   de **tous les tenants actifs en parallèle** ; distinction fine « tous injoignables »
   (503) vs « joignables sans correspondance » (401).
3. Au premier login KLASSCI réussi : upsert transactionnel du user local
   (`KlassciUserSynchronizer`), token Sanctum, réponse via `AuthResponsePresenter`,
   audit (`AuditLogger`).

### 1.5 Domaines métier

| Domaine | Entités | Logique clé |
|---|---|---|
| **Séances / Visio** | `Seance`, `ESBTPAttendance`, `SeanceUserHidden` | Cycle `programmee → active → terminee` ; salle Jitsi générée localement avec un identifiant aléatoire non dérivé de la séance, aucun appel serveur→Jitsi ; heartbeat 30 s → `last_seen_at` ; coordinateurs = observateurs fantômes (tracés, exclus des stats) |
| **Auto-close** | `AutoCloseEmptySeances` + 3 règles priorisées | Prof déconnecté ≥5 min > tous déconnectés ≥10 min > personne jamais venu ≥30 min ; gate `HeartbeatHealthChecker` (si TOUS les heartbeats sont morts >3 min, c'est le heartbeat qui est en panne → on ne ferme pas) ; fermeture transactionnelle avec recalcul des durées |
| **Quiz (natif LMS)** | `Quiz`, `QuizQuestion/Answer/Attempt` | Grading auto (QCM/multi/vrai-faux), `short_answer`/`essay` → correction manuelle (statut `submitted` en attente) ; stats dénormalisées recalculées **explicitement** (`QuizStatisticsService::recompute`, boot hooks supprimés comme anti-pattern) |
| **Évaluations (miroir KLASSCI)** | `Evaluation`, `EvaluationSubmission` | Notées sur barème /20 ; fenêtre temporelle interrogée **en live** chez KLASSCI au démarrage ; mode « entraînement » hors fenêtre (note non officielle) |
| **KnowledgeCheck** | `KnowledgeCheck(Attempt)` | Quiz de chapitre, questions en JSON ; si `is_required`, verrouille le chapitre suivant via `ChapterAccessGate` (progression séquentielle, best-score-wins) |
| **Contenu** | `Lesson`, `Chapter`, `*Progress`, `LessonResource` | State machine `not_started → in_progress → completed` ; conversion supports PPTX/DOCX/PDF → PNG par page via **ConvertAPI** (SaaS externe) avec renderer local alternatif (`PdfToPngRenderer`) |
| **Forum** | `ForumCategory/Topic/Post` | Une seule « solution » par topic ; deux sémantiques de fan-out volontairement distinctes (auteurs directs vs tous les participants) |
| **Notifications** | `Notification` (10 types) | In-app uniquement, pull (pas de temps réel) ; fan-out = 1 ligne DB par destinataire ; anti-double-envoi 24 h sur `visio_scheduled` |
| **Analytics / Reports** | services `AdminAnalytics`, `Report` | Caches 1-5 min scopés tenant ; 3 PDF DomPDF (présences, notes, activité) |

### 1.6 Synchronisation KLASSCI (scheduler)

Neuf tâches planifiées dans `routes/console.php` (toutes `withoutOverlapping()` +
`onOneServer()`) : sync séances 5 min, détection déconnexions 2 min, finalisation
présences 10 min (grâce 30 min après `heure_fin`), nettoyages quotidiens (séances
obsolètes, archives >2 semaines, évaluations sans soumission >7 j, purge audit),
rappels d'évaluations 08:00, purge notifications lues le dimanche.
`AutoCloseEmptySeances` a été ajouté au scheduler par la PR #386 (issue #369) —
à l'audit initial il existait sans être planifié.

---

## 2. Prérequis

### 2.1 Runtime

| Prérequis | Valeur | Source |
|---|---|---|
| PHP | `^8.2` (CI : 8.3) | `composer.json:9`, `.github/workflows/security.yml:38` |
| Framework | Laravel `^12.0` + Sanctum `^4.2` | `composer.json:14-15` |
| Extensions | `imagick` (renderer PDF local), `pdo_sqlite`/`pdo_mysql` | `app/Services/FileConversion/` |
| DB | défaut **sqlite** ; MySQL recommandé prod ; `Schema::defaultStringLength(191)` forcé | `config/database.php:19`, `app/Providers/AppServiceProvider.php:39` |
| Queue | **`database`** → un worker `queue:work` est requis pour dépiler | `config/queue.php:16` |
| Cache / Sessions | `database` (Redis optionnel, predis présent) | `config/cache.php:18` |
| Scheduler | cron `php artisan schedule:run` **chaque minute** — vital (voir §3.4) | `routes/console.php`, `docs/DEPLOYMENT_OPS.md` |

### 2.2 Services externes (le système ne fonctionne pas sans)

1. **KLASSCI** — `KLASSCI_API_URL`, `KLASSCI_API_TOKEN` (+ token par institution en DB,
   chiffré). Sans lui : login des utilisateurs non-supradmin impossible, sync séances/
   matières/classes morte, fenêtres d'évaluation indisponibles. Panne → 503 propagé.
   Réglages : `cache_ttl` 300 s, `timeout` 30 s, `ssl_verify`, `pool_size` 4
   (`config/services.php:47-59`).
2. **ConvertAPI** (SaaS payant) — `CONVERTAPI_SECRET`. Sans lui : conversion des supports
   de cours en images échoue (throw à l'init paresseuse, `app/Services/ConvertApiService.php:41-45`).
3. **Jitsi** — aucun prérequis serveur : la salle est un identifiant généré côté LMS,
   le client rejoint directement. La validation acceptait `jitsi,zoom,teams,bbb`
   (`ToggleVisioSeanceRequest.php:50`) mais **seul Jitsi est câblé** — traité par
   l'issue #377 (PR #393).

### 2.3 Variables d'environnement critiques

`APP_KEY` (chiffre les tokens KLASSCI en DB — sa perte rend les tokens irrécupérables),
`KLASSCI_API_URL`, `KLASSCI_API_TOKEN`, `CONVERTAPI_SECRET`, `SUPRADMIN_EMAIL`/
`SUPRADMIN_PASSWORD` (seeder), `SANCTUM_TOKEN_EXPIRATION` (défaut 10080 = 7 j),
`AUDIT_ENABLED`/`AUDIT_RETENTION_DAYS` (défaut 365), matrice `DB_*`/`MAIL_*`/`QUEUE_*`.

> ⚠️ À l'audit, **`.env.example` n'existait pas** (gitignoré) alors que `composer.json:44`
> et le guide de déploiement en dépendent. Suivi : issue #357 (durcissements config).
> Le guide de déploiement a été corrigé depuis (PR #384, issue #370).

### 2.4 Déploiement réel

Hébergement **cPanel mutualisé Linux** (`.cpanel.yml` → `/home/c2569688c/public_html/lms-backend`),
**migration VPS en préparation** (épique scalabilité #381, issue #367).
Le pipeline cPanel ne fait **ni `migrate` ni installation de cron** : migrations et
cron `schedule:run` sont manuels — la procédure de référence est désormais
`docs/DEPLOYMENT_OPS.md` (PR #386). Les scripts `scheduler.bat` /
`laravel-scheduler-task.xml` / `setup-scheduler-windows.ps1` ne concernent que le poste
de dev Windows (chemins codés en dur).

---

## 3. Effets de bord (à connaître avant toute modification)

### 3.1 Effets transversaux silencieux

- **Global scope tenant** : tout modèle utilisant `BelongsToInstitution` est filtré
  par `institution_id` sur *toutes* les requêtes, et auto-rempli à la création. Si le
  tenant n'est pas résolu (jobs, console, tests), le scope est **silencieusement skippé
  avec un simple warning** (`app/Models/Traits/BelongsToInstitution.php:59-77`) —
  choix documenté, mais tout code hors requête HTTP doit passer `institution_id`
  explicitement sous peine de fuite ou de lignes orphelines.
- **Audit automatique** : `created/updated/deleted` de `Evaluation`,
  `EvaluationSubmission`, `QuizAttempt` écrivent dans `audit_logs` (observer
  `AuditableObserver`, attributs `hidden` exclus des diffs, fail-safe : une erreur
  d'audit ne casse jamais l'action). Purge = uniquement `audit:purge`.
- **`ResolveInstitution` reset le `TenantManager` à chaque requête** — indispensable
  sous Octane/Swoole ; ne pas retirer.

### 3.2 Écritures déclenchées par les jobs (toutes les 2-10 min, en tâche de fond)

| Job | Écrit |
|---|---|
| `SyncKlassciSeances` (5 min) | **Crée** des `Seance` (visio Jitsi activée d'office), sync les classes/étudiants, **envoie des notifications**, archive les séances disparues de KLASSCI |
| `DetectDisconnectedParticipants` (2 min) | Passe `connected → disconnected` si heartbeat >5 min, calcule `duration_minutes` |
| `FinalizeSeanceAttendances` (10 min) | Clôt les présences 30 min après la fin théorique — ⚠️ bug tracké #390 (`heure_fin` absente du schéma) |
| `AutoCloseEmptySeances` (planifié depuis PR #386) | Ferme les visios vides/abandonnées (transactionnel) |
| `CleanOldEvaluations` (03:00) | **Soft-delete** des évaluations terminées >7 j sans soumission |
| `ArchiveOldSeances` (02:00) | Archive les séances actives créées il y a >2 semaines |

Conséquence : **si le cron ne tourne pas, les visios ne se ferment jamais et les
présences ne sont jamais finalisées** (origine de plusieurs incidents historiques).
Healthcheck et procédure : `docs/DEPLOYMENT_OPS.md`.

### 3.3 Sorties réseau et fichiers

- HTTP sortant : KLASSCI (proxy + pools parallèles + sync), ConvertAPI. Rien d'autre.
- Fichiers : PNG de conversion sous `storage/app/public/chapters/{chapterId}` ;
  logs `storage/logs/` (dont `scheduler.log`) ; backups de `content:fix-corruption`.
- Cache : entrées `Cache::remember` par appel KLASSCI ; **toute écriture proxy
  invalide le cache de tout le tenant** (soft-invalidation par timestamp).
- Notifications : créer/démarrer une séance, publier une leçon, répondre au forum,
  noter un étudiant ⇒ insertions en masse dans `notifications` (1 ligne/destinataire).

### 3.4 Pièges connus

- Deux conventions de statut de présence coexistent : `connected`/`disconnected`
  (flux visio, `esbtp_attendance`) vs `present`/`absent`/`late` (rapports PDF,
  `ReportGenerationService::generateAttendance`). Ne pas les mélanger — frontière
  documentée par la PR #393 ; le rapport à 0 % qui en découle est le bug #391.
- `FinalizeSeanceAttendances` lit `heure_fin`/`heure_debut` sur `Seance` : colonnes
  **absentes du schéma** → job cassé, tracké #390.
- `PersonalAccessToken::tokenable()` bypasse le scope tenant (`app/Models/PersonalAccessToken.php:14-17`) ;
  sans cela le supradmin devient introuvable. Ne pas « corriger » ce bypass.
- `route:cache` **fonctionne avec des routes closures** en Laravel 12 (sérialisation
  supportée) — mesuré le 2026-07-03. Ne pas invoquer le blocage du cache comme
  justification d'un refactoring de routes.

---

## 4. Dettes techniques et anti-patterns

### 4.1 Affirmations de conformité VÉRIFIÉES vraies

Le projet prétend (roadmap, mémoires de PR) être conforme à `PRODUCTION_STANDARDS.md`.
Vérification sur code réel au 2026-07-02 — **ces points sont vrais** : aucun fichier
`app/` >300 lignes (max 299), zéro Facade en code métier (les occurrences grep sont des
commentaires), zéro `env()` runtime, zéro `$e->getMessage()` renvoyé au client (137
usages, tous en log serveur), tokens chiffrés (`cast 'encrypted'`), zéro `Cache::flush()`,
quasi-zéro TODO, 1 seul `DB::raw` (contrôlé, sans input utilisateur), 157 fichiers de
test, PHPStan level 9 en CI, 9 checks CI dont guard de taille de fichiers.

### 4.2 Dettes CACHÉES (non documentées dans la roadmap) — par sévérité

**Statut de suivi (2026-07-03)** :

| Dette | Issue | Statut |
|---|---|---|
| C1 sqlite versionné | #362 | 🔄 Phase A résolue (PR #383 : dé-tracké + runbook) ; phase B (purge historique) après migration VPS #367 |
| C2 verrouillage concurrent | #355, #347, #346 | ⏳ Ouvertes (audit sécurité parallèle) |
| H1 baseline PHPStan | #363 | 🔄 Lot 1 mergé (PR #385, baseline 1049→702) ; restent lots 2/3 + ratchet CI |
| H2 .env.example / guide | #357, #370 | 🔄 Guide corrigé (PR #384, #370 fermée) ; `.env.example` porté par #357 |
| H3 Eloquent en controllers | #364 | ✅ Résolue (PR #392) — finding dérivé : #401 (colonnes fantômes, risque 500 prod) |
| M1 migrations `.disabled` | #376 | ✅ Résolue (PR #389) |
| M2 ops scheduler/worker | #369 | ✅ Résolue (PR #386 : `docs/DEPLOYMENT_OPS.md`, healthcheck, AutoClose planifié) |
| M3 closures de routes | #375 | ✅ PR #403 (CI verte) — voir correction de prémisse dans M3 |
| L1-L3 micro-dettes | #377 | 🔄 Documentation livrée (PR #393) ; tri racine en attente de validation |

**🔴 C1 — `database.sqlite` (831 Ko) versionné dans git.** `git ls-files` le confirmait.
Base réelle avec données → PII potentielle dans l'historique git public
(`github.com/ouedraogoissouf2012/lms_backend`). La roadmap marquait « SQLite→MySQL DONE »
mais le fichier de données restait tracké. Dé-tracké depuis (PR #383) ; la **purge de
l'historique** reste à faire (`docs/RUNBOOK_PURGE_HISTORIQUE.md`).

**🟠 C2 — Aucun verrouillage concurrent dans tout le projet** (`grep lockForUpdate app/` = 0
à l'audit). Cas concret : `QuizAttemptStartSubmitService::startAttempt`
(`app/Services/Quiz/QuizAttemptStartSubmitService.php:44-66`) fait check-then-act
(quota → `max+1` → `create`) sans transaction. Le dépassement de quota est *de facto*
bloqué par la contrainte unique `(quiz_id, user_id, attempt_number)`
(`database/migrations/2025_10_14_180300_create_quiz_attempts_table.php:51`), mais une
collision concurrente produit une **`QueryException` non gérée → 500** au lieu d'un 409/403
propre. `submitAttempt` (ligne 103-111) a le même TOCTOU sur `status` → double grading
possible en double-clic. Même motif sur KnowledgeCheck (sans contrainte unique — #346).

**🟠 H1 — `phpstan-baseline.neon` : ~1049 erreurs grand-fathered à l'audit (272 Ko).**
Le « level 9 » affiché en CI masque une dette de typage massive : `institution_id` non typé
sur les modèles (retrofit multi-tenant), dizaines de `Cannot access offset on mixed`
(tableaux là où des DTO s'imposent), relations non déclarées. Les refactorings futurs
perdent le filet. Lot 1 résorbé (PR #385, → 702 entrées).

**🟠 H2 — `.env.example` absent et gitignoré.** Le guide de déploiement citait en
plus un `.env.production.example` et des scripts `export_data.php`/`import_data.php`
**absents du repo** — documentation de déploiement corrigée depuis (PR #384).

**🟠 H3 — Résidu de logique métier dans les controllers Dashboard/Stats.**
~29 requêtes Eloquent inline (34 recensées précisément par la PR #392), concentrées dans
`DashboardTeacherController.php`, `DashboardAdminController.php`, `TeacherStatsController.php`.
Violation directe du §5 du manifeste ayant survécu au refactoring. Extraites depuis vers
3 services SRP (PR #392). **Finding dérivé majeur** : plusieurs de ces requêtes interrogent
des **colonnes inexistantes** (bloc quiz enseignant inerte, compteurs toujours 0, risque
500 sous MySQL) → issue #401.

**🟡 M1 — 2 migrations `.disabled` versionnées** (`create_forum_tables`,
`create_notifications_table`) : code mort semant le doute sur le schéma réel.
Supprimées (PR #389).

**🟡 M2 — Ops non versionnées, incohérentes avec la prod.** Scheduler outillé uniquement
pour Windows (chemins en dur) alors que la prod est cPanel Linux ; aucun cron ni worker
versionné. Résolu (PR #386) : `docs/DEPLOYMENT_OPS.md` + commande de healthcheck +
`AutoCloseEmptySeances` planifié.

**🟡 M3 — Closures avec requêtes Eloquent dans le fichier de routes** (endpoints publics
`/institutions/active`, `/institution/current` — à l'audit : `routes/api.php:40-74`,
déplacées ensuite dans `routes/api/core.php` par le split PR #343, extraites vers
`InstitutionDirectoryService`/`Controller` par la PR #403) : logique non testable dans
les routes + énumération publique des tenants (divulgation mineure, décision en attente
sur #375). **Correction de prémisse post-audit (mesurée)** : `route:cache` passait DÉJÀ
avec ces closures — Laravel 12 sérialise les routes closures ; la dette réelle était la
violation §5/§1.6 D, pas le cache.

**🟢 L1 — Signal de process** : ~40 fichiers `FIX_*/CORRECTION_*/DIAGNOSTIC_*/SOLUTION_*.md`
+ scripts `.ps1`/`.bat`/`.patch` + un fichier `nul` à la racine (gitignorés, mais présents).
Trace d'une longue phase « incident → patch → doc jetable » antérieure à la roadmap.
Tri en cours (#377). **L2** : `AuditLog::create()` statique dans `AuditLogger.php:90`
(entorse DI mineure, opportuniste). **L3** : 22 migrations en zigzag (drop/re-add même
jour sur l'unicité email) — churn du retrofit multi-tenant, cohérent mais révélateur
d'un schéma stabilisé tardivement.

### 4.3 Faux positifs écartés (vérifiés)

- Les 3 services `Seances{List,History,Detail}QueryService` ne sont **pas** de la
  duplication : responsabilités réellement distinctes, documentées croisées.
- Le double fan-out forum (service vs dispatcher) est **volontaire** et documenté
  (`ForumPostService.php:30-40`).
- `visio_type` multi-providers en validation avec Jitsi seul câblé : dette d'extension,
  pas un bug (restreint depuis, PR #393).

---

## 5. Chiffres de référence (au 2026-07-02)

174 routes API (+ miroir `/api/v1`, `/api/v2` vide) · 52 controllers · 128 services ·
33 modèles · 130 FormRequests · 3 middlewares custom · 7 jobs · 9 tâches planifiées
(10 avec `AutoCloseEmptySeances` depuis PR #386) · 9 commandes artisan · 64 migrations ·
157 fichiers de test · PHPStan level 9 (baseline 1049 → 702 après lot 1) ·
CI : 9 checks (`.github/workflows/security.yml`).
