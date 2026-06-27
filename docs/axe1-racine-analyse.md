# Axe #1 — Analyse des réponses « payload-racine »

> **Type** : analyse / décision d'architecture. **Aucune migration de code applicatif.**
> **Périmètre** : 11 controllers émettant `response()->json($variable, ...)` au lieu des fabriques
> centralisées `successResponse()` / `errorResponse()` du trait
> [`RespondsWithJson`](../app/Http/Controllers/Concerns/RespondsWithJson.php).
> **Branche** : `docs-api-racine-analyse` → `lms`.
> **Issues couvertes** : #318 #319 #320 #321 #322 #323 #324 #325 #326 #327 #328.

---

## 0. Résumé exécutif (à lire en premier)

L'énoncé de l'axe #1 partait d'une **prémisse à corriger**. Il supposait que ces controllers
« renvoient des payloads BRUTS … **SANS clé `success`** ». La relecture du code réel (services
sous-jacents, ligne à ligne) montre que **ce n'est vrai que pour les 3 controllers Proxy** sur leur
chemin de succès. Les **8 controllers non-proxy contiennent DÉJÀ une clé `success`** : l'enveloppe
y est construite **à la main dans la couche service**, et non via le trait centralisé.

Le vrai sujet n'est donc pas « ajouter `success` », mais **deux problèmes distincts** :

| Groupe | Controllers | Nature réelle | Décision |
|---|---|---|---|
| **A — Proxy KLASSCI** | ProxyAcademic (#326), ProxyDashboard (#327), ProxyOrganisation (#328) | **Passthrough** d'un contrat d'API externe. Le succès renvoie le corps KLASSCI **verbatim** (la clé `success` n'est même pas garantie). | **LAISSER TEL QUEL.** Wrapper = casser le contrat du proxy + double-enveloppe sur le fallback `enseignants`. |
| **B — Métier interne** | Chapter (#318), ChapterProgress (#319), EvaluationTeacher (#320), LMSAttendances (#321), SeanceParticipantMutation (#322), SeanceVisibilityMutation (#323), VisioLifecycle (#324), VisioParticipant (#325) | Enveloppe **déjà `{success, message?, data?}`**, mais **bâtie dans le service** (contourne le trait) ; quelques endpoints placent les données **hors de `data`**. | **CAS PAR CAS, non prioritaire.** Migration = refactoring interne à valeur client quasi nulle pour la plupart ; **breaking** pour 3 endpoints à clés non-canoniques. |

**Verdict final** : un chantier « uniformisation » dédié et big-bang **ne vaut pas le coût
aujourd'hui** (bénéfice fonctionnel faible, les clients reçoivent déjà `success`). On recommande une
**migration opportuniste** (quand un fichier est rouvert pour une autre raison) + un **sous-lot
breaking explicitement coordonné avec le frontend** pour les 3 endpoints non-canoniques. Détails en
[§4](#4-synthèse--un-chantier-uniformisation-vaut-il-le-coût-).

---

## 1. Méthodologie

Les controllers concernés sont *thin* : ils délèguent à des services qui retournent
`['status' => int, 'payload' => array]`, puis font :

```php
return response()->json($result['payload'], $result['status']);
```

La forme JSON réellement vue par le client est donc **celle du tableau `payload` construit dans le
service**, pas dans le controller. L'analyse a donc lu **les services**, méthode par méthode, pour
relever la forme exacte de chaque `payload` (chemin succès **et** chemins d'erreur) et la présence
ou non d'une clé `success`. Chaque constat est sourcé `fichier:ligne`.

Le **contrat canonique** de référence (trait `RespondsWithJson`,
[`RespondsWithJson.php:53-112`](../app/Http/Controllers/Concerns/RespondsWithJson.php#L53-L112)) est :

- Succès : `{ "success": true, "message"?, "data"?, "meta"? }` (clé omise si vide).
- Erreur : `{ "success": false, "message", "errors"? }` (+ status HTTP).

Toutes les routes du périmètre sont derrière `auth:sanctum` (+ `role:*` selon l'endpoint)
— vérifié dans [`routes/api.php`](../routes/api.php) (groupes l.106-208 pour proxy, l.228-256 pour
chapitres, l.474-622 pour LMS, l.646-706 pour évaluations).

---

## 2. Groupe A — Controllers Proxy KLASSCI (passthrough)

### Mécanique commune

Les 3 controllers proxy injectent `KlassciProxyService`. Ses méthodes `get/post/put/delete`
retournent **directement** le résultat de `KlassciHttpClient::executeHttp()`, lui-même issu de
`$response->json()` **sans aucune transformation** :

- `KlassciProxyService::get()` → `return $result;` — [`KlassciProxyService.php:105`](../app/Services/KlassciProxyService.php#L105)
- `KlassciProxyService::post()` → `return $result;` — [`KlassciProxyService.php:119`](../app/Services/KlassciProxyService.php#L119)
- décodage brut : `$result = $response->json();` (fallback `[]` si non-array) — `KlassciHttpClient::executeHttp()`

Le LMS **n'ajoute ni ne garantit** de clé `success` : le code la lit défensivement
(`$result['success'] ?? false`), preuve qu'elle peut être absente. **C'est le contrat de l'API
KLASSCI externe qui transite, pas un contrat LMS.**

Les **erreurs**, elles, sont déjà enveloppées proprement par le trait
[`RendersKlassciProxyErrors`](../app/Http/Controllers/API/Proxy/Concerns/RendersKlassciProxyErrors.php#L30-L43)
(`{success:false, message}` en 503/500, sans fuite de `$e->getMessage()` — conforme §1.2).

### Détail par endpoint

| # | Controller / méthode | Réponse succès | Réponse erreur | Nature | Reco |
|---|---|---|---|---|---|
| #326 | `ProxyAcademicController::evaluations` ([:37](../app/Http/Controllers/API/Proxy/ProxyAcademicController.php#L37)) | corps KLASSCI **verbatim** (`response()->json($data)`) | `proxyErrorResponse()` → `{success:false,message}` 503/500 | Passthrough | **Laisser tel quel** |
| #326 | `…::emploiTemps` ([:51](../app/Http/Controllers/API/Proxy/ProxyAcademicController.php#L51)) | corps KLASSCI verbatim | idem | Passthrough | **Laisser tel quel** |
| #326 | `…::saveNotes` ([:65](../app/Http/Controllers/API/Proxy/ProxyAcademicController.php#L65)) | corps KLASSCI verbatim (POST) | idem | Passthrough | **Laisser tel quel** |
| #326 | `…::savePresences` ([:86](../app/Http/Controllers/API/Proxy/ProxyAcademicController.php#L86)) | corps KLASSCI verbatim (POST) | idem | Passthrough | **Laisser tel quel** |
| #326 | `…::updateCoursStatut` ([:115](../app/Http/Controllers/API/Proxy/ProxyAcademicController.php#L115)) | corps KLASSCI verbatim (PUT) | idem | Passthrough | **Laisser tel quel** |
| #327 | `ProxyDashboardController::studentDashboard` ([:41](../app/Http/Controllers/API/Proxy/ProxyDashboardController.php#L41)) | corps KLASSCI verbatim (token user) | `errorResponse()` 401 si pas de token + `proxyErrorResponse()` | Passthrough | **Laisser tel quel** |
| #327 | `…::teacherDashboard` ([:80](../app/Http/Controllers/API/Proxy/ProxyDashboardController.php#L80)) | corps KLASSCI verbatim (token user) | idem | Passthrough | **Laisser tel quel** |
| #328 | `ProxyOrganisationController::structure/classes/etudiants/matieres/matiereDetails/filieres/niveauxEtudes` | corps KLASSCI verbatim | `proxyErrorResponse()` | Passthrough | **Laisser tel quel** |
| #328 | `…::enseignants` ([:104](../app/Http/Controllers/API/Proxy/ProxyOrganisationController.php#L104)) | KLASSCI verbatim **OU** fallback local **déjà enveloppé** `{success,data,meta}` ([:126-134](../app/Http/Controllers/API/Proxy/ProxyOrganisationController.php#L126-L134)) | `proxyErrorResponse()` | Passthrough **+ fallback enveloppé** | **Laisser tel quel** (wrapper global = **double-enveloppe** du fallback) |
| #328 | `…::testConnection` ([:172](../app/Http/Controllers/API/Proxy/ProxyOrganisationController.php#L172)) | **déjà** `{success,message,api_url}` (diagnostic interne, pas un passthrough) | `proxyErrorResponse()` | Diagnostic interne | **Laisser tel quel** (déjà conforme) |

**Justification (Groupe A)** : envelopper le chemin de succès réécrirait un contrat externe que des
consommateurs (frontend, intégrations) lisent tel quel. Sur `enseignants`, un wrapper global
produirait `{success, data:{success, data, meta}}` (double enveloppe) en mode fallback. Les erreurs
sont déjà au contrat canonique. **Aucune action.**

---

## 3. Groupe B — Controllers métier interne (enveloppe déjà présente, bâtie en service)

> Constat-clé : **toutes** ces réponses contiennent **déjà** `success` (true/false). La « dette »
> n'est donc pas l'absence d'enveloppe mais (a) sa **construction hors trait** (DRY) et, pour 3
> endpoints, (b) un **placement des données non-canonique** (hors de `data` / `meta`).

### 3.1 Sous-groupe B1 — déjà au contrat `{success, message?, data?}` (écart = DRY interne seulement)

Pour ces endpoints, la forme vue par le client correspond déjà au contrat canonique. La seule dette
est que l'enveloppe est assemblée dans le service au lieu de passer par le trait.

| # | Controller / méthode | Forme succès (payload) | Forme erreur (payload) | Source |
|---|---|---|---|---|
| #318 | `ChapterController` : index, show, store, uploadFile, update, destroy, reorder (7) | `{success:true, data?, message?}` | `{success:false, message}` | `ChapterCrudService.php` (l.56,90,162,199,237,280…) + `ChapterFileUploadService.php:74,88` |
| #319 | `ChapterProgressController` : getLessonProgress, getChapterProgress, markAsCompleted, updateTimeSpent, resetLessonProgress (5) | `{success:true, data?, message?}` | `{success:false, message}` (403/400/500) | `ChapterProgressService.php` (l.36,67,140,188,245 ; erreurs l.98,118,211,223,259) |
| #320 | `EvaluationTeacherController` : getResultsByClass, getSubmissions, preview (3) | `{success:true, data}` | `{success:false, message, error}` ⚠️ clé `error` | `TeacherEvaluationResultsService.php:104,122` ; `TeacherEvaluationViewService.php:94,116,167,202` |
| #321 | `LMSAttendancesController::syncAttendancesFromVideoSession` (1) | `{success:true, message, data}` | `{success:false, message, error}` ⚠️ | `VideoSessionAttendancesSyncer.php:78,97` |
| #322→#325 | Seance/Visio mutations & lifecycle & participant (cf. tableau ci-dessous) | `{success:true, message?, data?}` | `{success:false, message, error?}` ⚠️ | cf. §3.3 |

> ⚠️ **`error`** : les chemins d'erreur ajoutent une clé `'error' => 'Une erreur est survenue.'`
> (constante — **pas** de fuite d'exception, §1.2 respecté), absente du contrat canonique (qui prévoit
> `errors` structuré). Écart mineur, mais **retirer `error` serait un changement de contrat** pour tout
> client le lisant.

### 3.2 Sous-groupe B2 — placement des données NON-canonique (canonisation = breaking)

Trois endpoints contiennent bien `success`, mais placent les données **à la racine** au lieu de
`data` / `meta`. Les normaliser change la forme lue par le frontend → **breaking**.

| # | Endpoint | Forme actuelle (payload) | Forme canonique cible | Impact frontend si migré |
|---|---|---|---|---|
| #321 | `LMSAttendancesController::getSeanceAttendances` | `{success, seance, statistics, attendances}` — données **à la racine** ([`SeanceAttendancesQueryService.php:71-79`](../app/Services/Attendances/SeanceAttendancesQueryService.php#L71-L79)) | `{success, data:{seance, statistics, attendances}}` | `res.seance/statistics/attendances` → `res.data.*` (**breaking**) |
| #321 | `LMSAttendancesController::getAttendanceHistory` | `{success, data, pagination}` — `pagination` **à la racine** ([`AttendanceHistoryQueryService.php:55-67`](../app/Services/Attendances/AttendanceHistoryQueryService.php#L55-L67)) | `{success, data, meta:{…pagination}}` | `res.pagination` → `res.meta` (**breaking**) |
| #322 | `LMSSeanceParticipantMutationController::validateParticipant` | `{success, authorized, role/reason/user_role, message?}` — flags **à la racine** ([`ParticipantValidationService.php:63-86`](../app/Services/Seances/Mutations/ParticipantValidationService.php#L63-L86)) | `{success, data:{authorized, role, …}}` | `res.authorized/role/reason` → `res.data.*` (**breaking**) |

### 3.3 Détail Visio / Seance mutations (#322–#325)

| # | Service / méthode | Succès | Erreurs | Note |
|---|---|---|---|---|
| #323 | `SeanceHideService::hide` / `unhide` | `{success, message, data}` (l.61,127) | `{success, message}` 404 ; `{success,message,error}` 500 | conforme B1 |
| #323 | `SeanceDeleteService::delete` | `{success, message}` (l.73) | `{success,message}` 404/422 ; `{…,error}` 500 | conforme B1 |
| #323 | `VisioToggleService::toggle` | `{success, message, data}` (l.102) | `{success,message,error}` 500 | conforme B1 |
| #324 | `VisioActivationService::activate` / `deactivate` | `{success, message, data}` (l.91,157) | `{success,message}` 404 ; `{…,error}` 500 | conforme B1 |
| #324 | `VisioSessionService::start` / `end` | `{success, message, data}` (l.95,168) | `{success,message}` 404/400 ; `{…,error}` 500 | conforme B1 |
| #325 | `VisioParticipantSessionService::join` / `leave` | `{success, message, data}` (l.109,171) | `{success,message}` 404/400 ; `{…,error}` 500 | conforme B1 |
| #325 | `VisioHeartbeatService::heartbeat` | `{success, data}` (l.78, **sans** message) | `{success,message}` 404 ; `{…,error}` 500 | conforme B1 |
| #325 | `VisioParticipantsListService::list` | `{success, data}` (l.140, **sans** message) | `{success,message}` 404 ; `{…,error}` 500 | conforme B1 |
| #322 | `ParticipantValidationService::validate` | `{success, authorized, …}` (l.66,81,166) | `{success, authorized:false, reason, message?}` (l.263) | **B2 (non-canonique)** |

**Justification (Groupe B)** : le contrat fonctionnel (`success` présent) est **déjà** satisfait.
Pour B1, router via le trait est du refactoring DRY interne : valeur client ≈ 0, mais risque de
régression non nul (il faut préserver **exactement** chaque omission de clé). Pour B2, la
canonisation est un **vrai breaking change** nécessitant une coordination frontend.

---

## 4. Synthèse — un chantier « uniformisation » vaut-il le coût ?

### 4.1 Réponse

**Non, pas un chantier dédié big-bang maintenant.** Le bénéfice fonctionnel est faible : tous les
endpoints du périmètre B renvoient déjà `success`, et le périmètre A *doit* rester brut. Le bénéfice
restant est purement architectural (source unique d'enveloppe), modéré, et le coût/risque est réel
(≈ 30+ formes de réponses distinctes à préserver, + coordination frontend pour B2).

### 4.2 La solution recommandée (unique)

1. **Groupe A (#326/#327/#328)** : **figer comme passthrough**. Documenter l'intention (ce fichier
   fait foi) et **exclure** ces controllers de tout futur lot d'uniformisation.
2. **Groupe B1 (#318/#319/#320/#321-sync/#323/#324/#325)** : **migration opportuniste**. Quand un de
   ces fichiers est rouvert pour une autre raison, déplacer la construction d'enveloppe service →
   controller via `successResponse()/errorResponse()`, **en préservant les clés à l'octet près**
   (mêmes omissions). Aucun lot dédié. Priorité basse.
3. **Groupe B2 (#321-getSeanceAttendances, #321-getAttendanceHistory, #322-validateParticipant)** :
   **sous-lot breaking explicite, versionné et coordonné frontend.** Ne jamais le faire « à
   l'aveugle ».

### 4.3 Coût frontend estimé

| Lot | Endpoints | Coût frontend |
|---|---|---|
| A (proxy) | 3 controllers / ~17 endpoints | **0** (aucun changement) |
| B1 (DRY) | ~25 réponses | **≈ 0** *si* clés préservées (la clé `error` des chemins d'erreur ne doit pas être retirée sans audit des `catch` frontend) |
| B2 (canonique) | 3 endpoints | **Réel** : audit des consommateurs + réécriture des accès (`res.seance`→`res.data.seance`, `res.pagination`→`res.meta`, `res.authorized`→`res.data.authorized`) + tests E2E |

### 4.4 Les 15 questions (extraits pertinents — PRODUCTION_STANDARDS §4)

- **Q11 (meilleure vs rapide)** : laisser A en passthrough et B en migration opportuniste **est** la
  meilleure option — un big-bang serait *plus de travail* pour *moins de valeur* et *plus de risque*.
- **Q12 (alternatives écartées)** : (a) *tout wrapper* — rejeté : casse le contrat proxy + double
  enveloppe `enseignants` + breaking B2 sans coordination ; (b) *ne rien faire jamais* — rejeté :
  laisse la dette DRY B1 et l'incohérence B2 non tracées.
- **Q14 (source)** : contrat canonique = `RespondsWithJson` (code réel cité) ; passthrough =
  `KlassciProxyService`/`KlassciHttpClient` (code réel cité). Aucune affirmation non sourcée.
- **Q15 (ce qui me ferait changer d'avis)** : si un audit frontend prouvait que **personne** ne
  dépend des clés racine de B2, alors B2 deviendrait du B1 (migration opportuniste sans coordination).
  Inversement, si un client externe dépendait des réponses proxy *transformées*, A resterait figé de
  toute façon.

---

## 5. Tableau récapitulatif global

| Issue | Controller | # réponses racine | Nature | `success` déjà présent ? | Reco |
|---|---|---|---|---|---|
| #318 | ChapterController | 7 | Métier (B1) | ✅ oui | Migration opportuniste |
| #319 | ChapterProgressController | 5 | Métier (B1) | ✅ oui | Migration opportuniste |
| #320 | EvaluationTeacherController | 3 | Métier (B1, clé `error`) | ✅ oui | Migration opportuniste |
| #321 | LMSAttendancesController | 3 | Métier (1×B1 + 2×**B2**) | ✅ oui | sync : opportuniste ; getSeanceAttendances & getAttendanceHistory : **lot breaking coordonné** |
| #322 | LMSSeanceParticipantMutationController | 1 | Métier (**B2**) | ✅ oui | **lot breaking coordonné** |
| #323 | LMSSeanceVisibilityMutationController | 4 | Métier (B1) | ✅ oui | Migration opportuniste |
| #324 | LMSVisioLifecycleController | 4 | Métier (B1) | ✅ oui | Migration opportuniste |
| #325 | LMSVisioParticipantController | 4 | Métier (B1) | ✅ oui | Migration opportuniste |
| #326 | ProxyAcademicController | 5 | **Passthrough** | ❌ non (contrat externe) | **Laisser tel quel** |
| #327 | ProxyDashboardController | 2 | **Passthrough** | ❌ non (contrat externe) | **Laisser tel quel** |
| #328 | ProxyOrganisationController | 10 | **Passthrough** (+ fallback/diagnostic enveloppés) | ❌/✅ mixte | **Laisser tel quel** |

---

*Analyse menée sur la branche `docs-api-racine-analyse` (base `origin/lms`), code relu ligne à ligne
dans les services — aucune supposition. Aucun fichier sous `app/` modifié.*
