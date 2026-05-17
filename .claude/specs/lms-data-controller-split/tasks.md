# Tasks — LMSDataController split

**Spec workflow** : CONTRIBUTING.md §A
**Branche racine** : `refactor/lms-data-controller-split` (spec only)
**Branches feature** : `refactor/lms-split-{letter}-{controller}`
**Spec docs** : [requirements.md](requirements.md) · [design.md](design.md)

---

## 0. Préparation

- [x] **0.1** Branche racine `refactor/lms-data-controller-split` créée
- [x] **0.2** Spec dir `.claude/specs/lms-data-controller-split/` créé
- [x] **0.3** Inventaire 32 méthodes (cf. requirements.md)
- [x] **0.4** Investigation couplage interne (cf. design.md §1)
- [x] **0.5** Décision routes conflict REQ-4 + dead code REQ-5 (cf. design.md §2.6, §2.7)
- [ ] **0.6** Spec approuvée user

---

## Phase B — Extractions (10 PRs)

Pattern par PR (à reproduire sur les 10) :
1. Branche dédiée depuis `lms` à jour
2. Création du controller (avec `extends AuthenticatedController`)
3. Migration des méthodes (copy-paste depuis `LMSDataController` + adaptations)
4. Update `routes/api.php` (controller class référencée)
5. Validation PHPStan (0 errors, baseline propre)
6. Tests Feature HTTP minimum (~3 cas par controller)
7. 3 audits §A (security strict + architect + reviewer)
8. Commit + push
9. **User merge sur GitHub** (pas `gh pr merge` autonome — cf. mémoire `feedback_no_commit_without_approval`)
10. Pull lms à jour pour PR suivante

---

## PR A — `LMSClassesController` (2 méthodes, pilote)

- [ ] **A.1** Branche `refactor/lms-split-a-classes` depuis lms
- [ ] **A.2** Créer `app/Http/Controllers/API/LMS/LMSClassesController.php`
- [ ] **A.3** Constructeur : injecter `KlassciProxyService` + `ClasseSyncService`
- [ ] **A.4** Migrer `classeDetails(int $classeId, Request $request)` (L53-242 de LMSDataController)
- [ ] **A.5** Migrer `classeEtudiants(int $classeId, Request $request)` (L244-309)
- [ ] **A.6** Pour chaque méthode : `$user = $request->user()` → `$user = $this->authenticatedUser($request)`
- [ ] **A.7** Update `routes/api.php` : controller class = `LMS\LMSClassesController`
- [ ] **A.8** Garder LMSDataController inchangé (lignes 53-309 conservées) — sera retiré en Phase C
- [ ] **A.9** PHPStan validation (composer phpstan:baseline + analyse)
- [ ] **A.10** Tests Feature : `tests/Feature/LMS/Classes/ClasseDetailsTest.php` (~3 cas)
- [ ] **A.11** Tests Feature : `tests/Feature/LMS/Classes/ClasseEtudiantsTest.php` (~3 cas)
- [ ] **A.12** 3 audits §A
- [ ] **A.13** Commit + push
- [ ] **A.14** Présenter PR au user (URL + résumé). **Attendre `pr merger` avant PR C.**

---

## PR C — `LMSMatieresController` (3 méthodes + helper privé)

- [ ] **C.1** Branche `refactor/lms-split-c-matieres` depuis lms
- [ ] **C.2** Créer `app/Http/Controllers/API/LMS/LMSMatieresController.php`
- [ ] **C.3** Constructeur : injecter `KlassciProxyService`
- [ ] **C.4** Migrer `matiereDetails(int $matiereId, Request $request)` (L311-770)
- [ ] **C.5** Migrer `adminMatieresList(Request $request)` (L3694-3852)
- [ ] **C.6** Migrer `myMatieres(Request $request)` (L4391-4542)
- [ ] **C.7** Migrer helper privé `getMatieresEnrichiesForEnseignant()` (L4172-4389) en méthode privée
- [ ] **C.8** Pour chaque méthode publique : `$request->user()` → `$this->authenticatedUser($request)`
- [ ] **C.9** Update `routes/api.php` (3 routes Matieres)
- [ ] **C.10** PHPStan validation
- [ ] **C.11** Tests Feature : `tests/Feature/LMS/Matieres/*` (~3 cas par méthode)
- [ ] **C.12** 3 audits §A
- [ ] **C.13** Commit + push + présenter PR. Attendre user merge.

---

## PR B — `LMSEnseignantsController` (1 méthode + cleanup dead code)

- [ ] **B.1** Branche `refactor/lms-split-b-enseignants` depuis lms (post-merge C)
- [ ] **B.2** Créer `app/Http/Controllers/API/LMS/LMSEnseignantsController.php`
- [ ] **B.3** Constructeur : injecter `KlassciProxyService`. Si nécessaire, dépendance sur `LMSMatieresController` ou extraction du helper en `MatiereEnrichmentService` — décision PR-time.
- [ ] **B.4** Migrer `getEnseignantsFromKlassci(Request $request)` (L4106-4170)
- [ ] **B.5** **Supprimer `getEnseignants()` (L3854-4104)** — confirmé dead code par grep
- [ ] **B.6** `$request->user()` → `$this->authenticatedUser($request)`
- [ ] **B.7** Update `routes/api.php` (1 route Enseignants)
- [ ] **B.8** PHPStan validation
- [ ] **B.9** Tests Feature : ~3 cas
- [ ] **B.10** 3 audits §A
- [ ] **B.11** Commit + push + présenter PR. Attendre user merge.

---

## PR D — `LMSNotificationsPreferencesController` (2 méthodes)

- [ ] **D.1** Branche `refactor/lms-split-d-notifications` depuis lms
- [ ] **D.2** Créer `app/Http/Controllers/API/LMS/LMSNotificationsPreferencesController.php`
- [ ] **D.3** Constructeur : injecter `NotificationService` + `KlassciProxyService`
- [ ] **D.4** Migrer `getNotificationPreferences(int $userId, Request $request)` (L1455-1503)
- [ ] **D.5** Migrer `sendSessionReminder(...)` (L2132-2177)
- [ ] **D.6** `$request->user()` → `$this->authenticatedUser($request)`
- [ ] **D.7** Update `routes/api.php` (2 routes Notifications)
- [ ] **D.8** PHPStan validation
- [ ] **D.9** Tests Feature : ~3 cas par méthode (+ vérifier cross-tenant pour `getNotificationPreferences` — REQ-9)
- [ ] **D.10** 3 audits §A
- [ ] **D.11** Commit + push + présenter PR. Attendre user merge.

---

## PR E — `SeanceQueryService` (préparatoire, bloque F/H/I)

- [ ] **E.1** Branche `refactor/lms-split-e-seance-query-service` depuis lms
- [ ] **E.2** Créer `app/Services/SeanceQueryService.php`
- [ ] **E.3** Constructeur : injecter `KlassciProxyService`
- [ ] **E.4** Méthode `getSeanceDetailsArray(int $seanceId, User $user): array` — extraction de la logique métier de `seanceDetails` (L1505-2004)
- [ ] **E.5** Méthode `getProgrammation(int $seanceId, User $user): ?array` — extraction de la partie programmation
- [ ] **E.6** Bien typer en retours `array` (PHPDoc + types stricts si possible)
- [ ] **E.7** Tests unitaires `tests/Unit/Services/SeanceQueryServiceTest.php` (~5 cas, mock `KlassciProxyService`)
- [ ] **E.8** PHPStan validation (0 errors)
- [ ] **E.9** **Pas de modification de `LMSDataController` pour cette PR** — service standalone
- [ ] **E.10** 3 audits §A
- [ ] **E.11** Commit + push + présenter PR. Attendre user merge.

---

## PR G — `AttendanceStatusService` (préparatoire, bloque H/I)

- [ ] **G.1** Branche `refactor/lms-split-g-attendance-service` depuis lms (post-merge E)
- [ ] **G.2** Créer `app/Services/AttendanceStatusService.php`
- [ ] **G.3** Méthode `determine(...)` — extraction de la logique de `determineAttendanceStatus()` (L3355-...) en service stateless
- [ ] **G.4** Tests unitaires `tests/Unit/Services/AttendanceStatusServiceTest.php` (~6 cas matrix : on time, late, absent, excused, edge cases)
- [ ] **G.5** PHPStan validation
- [ ] **G.6** 3 audits §A
- [ ] **G.7** Commit + push + présenter PR. Attendre user merge.

---

## PR F — `LMSSeancesController` (11 méthodes, ~1500 lignes ⚠️)

- [ ] **F.1** Branche `refactor/lms-split-f-seances` depuis lms (post-merge E)
- [ ] **F.2** Créer `app/Http/Controllers/API/LMS/LMSSeancesController.php`
- [ ] **F.3** Constructeur : injecter `KlassciProxyService` + `SeanceQueryService`
- [ ] **F.4** Migrer 11 méthodes (séances générales) :
  - `upcomingSeances` (L772)
  - `seanceDetails` (L1505) — **refactor pour utiliser `SeanceQueryService`** au lieu d'inline
  - `seanceParticipants` (L965)
  - `validateParticipant` (L1080)
  - `toggleVisioSeance` (L2006) — utilise helper privé `getSeanceDataFromKlassci`
  - `myTeachingSeances` (L2179)
  - `myClassesSeances` (L2367)
  - `hideSeance` (L3579)
  - `unhideSeance` (L3632)
  - `getSeancesHistory` (L4672)
  - `deleteSeance` (L4966)
- [ ] **F.5** Migrer helper privé `getSeanceDataFromKlassci()` (L4494-...)
- [ ] **F.6** Tous les `$request->user()` migrés
- [ ] **F.7** Update `routes/api.php` (11 routes Seances)
- [ ] **F.8** PHPStan validation
- [ ] **F.9** Tests Feature : focus sur les méthodes les plus critiques (`validateParticipant`, `deleteSeance`, `hideSeance` permission)
- [ ] **F.10** 3 audits §A (attention spécifique aux bugs latents — pattern Batches 8/9)
- [ ] **F.11** Commit + push + présenter PR. Attendre user merge.

---

## PR H — `LMSAttendancesController` (3 méthodes)

- [ ] **H.1** Branche `refactor/lms-split-h-attendances` depuis lms (post-merge G + F)
- [ ] **H.2** Créer `app/Http/Controllers/API/LMS/LMSAttendancesController.php`
- [ ] **H.3** Constructeur : injecter `KlassciProxyService` + `SeanceQueryService` + `AttendanceStatusService`
- [ ] **H.4** Migrer 3 méthodes :
  - `syncAttendancesFromVideoSession` (L1355)
  - `getAttendanceHistory` (L4544) — **refactor pour utiliser `SeanceQueryService`** au lieu de `$this->seanceDetails`
  - `getSeanceAttendances` (L4801)
- [ ] **H.5** Update `routes/api.php` (3 routes Attendances)
- [ ] **H.6** PHPStan validation
- [ ] **H.7** Tests Feature : ~3 cas par méthode
- [ ] **H.8** 3 audits §A
- [ ] **H.9** Commit + push + présenter PR. Attendre user merge.

---

## PR I — `LMSVisioController` (8 méthodes + résolution REQ-4)

- [ ] **I.1** Branche `refactor/lms-split-i-visio` depuis lms (post-merge G + F)
- [ ] **I.2** **Coordination frontend** : vérifier dans `lms-frontend` les appels à `GET /seances/{id}/participants` (séance vs visio).
  - Si frontend appelle pour récupérer participants visio → coordonner renommage
  - Si frontend n'appelle PAS la méthode visio → confirmer dead code et supprimer
- [ ] **I.3** Créer `app/Http/Controllers/API/LMS/LMSVisioController.php`
- [ ] **I.4** Constructeur : injecter `KlassciProxyService` + `SeanceQueryService` + `AttendanceStatusService`
- [ ] **I.5** Migrer 8 méthodes visio :
  - `activateVisio` (L2562)
  - `deactivateVisio` (L2730)
  - `startVisio` (L2791)
  - `endVisio` (L2898)
  - `joinVisio` (L2965)
  - `getVisioParticipants` (L3058) — **refactor pour utiliser `SeanceQueryService`** au lieu de `$this->seanceDetails`
  - `leaveVisio` (L3449)
  - `heartbeatVisio` (L3517)
- [ ] **I.6** **REQ-4** : route `getVisioParticipants` renommée → `GET /lms/seances/{seanceId}/visio-participants` (au lieu du conflit `participants`)
- [ ] **I.7** Update `routes/api.php` (8 routes Visio + 1 route renommée)
- [ ] **I.8** PHPStan validation
- [ ] **I.9** Tests Feature : focus sur le cycle complet visio (activate → start → join → heartbeat → leave → end → deactivate)
- [ ] **I.10** 3 audits §A (attention sécurité — actions visio = réelles modifs d'état)
- [ ] **I.11** Commit + push + présenter PR avec **note explicite sur le changement de route** pour frontend. Attendre user merge + confirmation frontend déployé.

---

## Phase C — Cleanup final

### PR J — Suppression `LMSDataController.php`

- [ ] **J.1** Branche `refactor/lms-split-j-cleanup` depuis lms (post-merge de toutes les PRs A-I)
- [ ] **J.2** **Vérification** : grep dans tout le code pour confirmer 0 référence à `LMSDataController` (sauf import dans `routes/api.php` qui doit être retiré)
- [ ] **J.3** Suppression du fichier `app/Http/Controllers/API/LMSDataController.php`
- [ ] **J.4** Retirer `use App\Http\Controllers\API\LMSDataController;` de `routes/api.php`
- [ ] **J.5** PHPStan validation (devrait drop encore une grosse partie du baseline)
- [ ] **J.6** Vérifier `php artisan route:list` : 31 routes LMS toujours fonctionnelles, pointant vers les bons nouveaux controllers
- [ ] **J.7** Mettre à jour `docs/SECURITY_CI.md` (entrée historique baseline)
- [ ] **J.8** Créer/mettre à jour `docs/LMS_CONTROLLERS_OVERVIEW.md` pour orienter les futurs développeurs (mapping URL → controller)
- [ ] **J.9** 3 audits §A
- [ ] **J.10** Commit + push + présenter PR. Attendre user merge.

---

## Definition of Done (Spec entière)

- [ ] 7 nouveaux controllers dans `app/Http/Controllers/API/LMS/`
- [ ] 2 nouveaux services partagés (`SeanceQueryService`, `AttendanceStatusService`)
- [ ] `LMSDataController.php` supprimé
- [ ] 31 routes LMS toutes pointent vers leur nouveau controller, contrat HTTP préservé (sauf REQ-4 renommage coordonné)
- [ ] PHPStan baseline réduit d'environ -58 violations + bonus latents découverts
- [ ] Tests Feature HTTP minimum par controller
- [ ] Tests unit pour les 2 services
- [ ] Aucun `User::isAdmin()` ambigu introduit
- [ ] 10 PRs avec 3 audits PASS chacune
- [ ] Documentation mise à jour (`docs/SECURITY_CI.md` + `LMS_CONTROLLERS_OVERVIEW.md` si créé)
- [ ] Issue #79-like : marquer la roadmap REFACTORING_ROADMAP.md TIER 1 comme complet

---

## Notes opérationnelles

### Pattern de migration par méthode

```
1. Identifier la méthode source dans LMSDataController (lignes exactes)
2. Copier-coller dans le nouveau controller
3. Adapter : `$user = $request->user()` → `$user = $this->authenticatedUser($request)`
4. Vérifier les services injectés disponibles
5. Si appel `$this->seanceDetails()` → remplacer par `$this->seanceQuery->getSeanceDetailsArray()`
6. Si appel `$this->determineAttendanceStatus()` → remplacer par `$this->attendanceStatus->determine()`
7. NE PAS supprimer la version originale dans LMSDataController (Phase C cleanup le fera)
```

### Garde-fous (leçons des batches précédents)

- **Batch 8 lesson** : `grep "Auth::"` complet avant retrait import (pas seulement `Auth::user`)
- **Batch 9 lesson** : vérifier que toutes méthodes appelées sur `$user` existent dans `User` model (isStudent, isTeacher, isCoordinator, isAdmin)
- **Batch 10 lesson** : nettoyer dead-code révélé par typage strict, ne pas masquer dans baseline

### Coordination user

- Chaque PR : **commit + push + présenter URL**, puis attendre `pr merger` / `branch merger pr X` avant d'enchaîner sur la suivante
- Si un audit révèle un BLOCKER : présenter au user avant correction
- Si un finding sécurité cross-tenant est trouvé : ouvrir un PR sécurité séparé (pattern #87/#91/#98/#102/#103)

### Effort par PR (estimation après inventaire)

| PR | Méthodes | Lignes nouveau controller | Effort estimé |
|---|---|---|---|
| A Classes | 2 | ~270 | 3h |
| C Matieres | 3 | ~620 | 4h |
| B Enseignants | 1 (+1 deleted) | ~290 | 2h |
| D Notifications | 2 | ~700 | 3h |
| E SeanceQueryService | — | service (~150) | 3h |
| G AttendanceStatusService | — | service (~80) | 2h |
| F Seances | 11 | ~1500 | 6h |
| H Attendances | 3 | ~400 | 4h |
| I Visio + REQ-4 | 8 | ~1200 | 6h |
| J Cleanup | — | suppression | 2h |
| **Total** | 30 publiques | ~5210 | **~35h** |

= **~1-2 semaines ouvrables** (selon disponibilité user pour les merges entre PRs).
