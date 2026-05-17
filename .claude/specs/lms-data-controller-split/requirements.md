# Requirements — LMSDataController split (god object refactor)

**Spec workflow** : CONTRIBUTING.md §A
**Date** : 2026-05-17
**Auteur** : Daisy / Claude Opus 4.7

---

## Contexte

`app/Http/Controllers/API/LMSDataController.php` est un **god object de 5014 lignes** avec **32 méthodes publiques** + 1 constructeur. Il porte 7 domaines fonctionnels distincts mélangés.

Violations actuelles :
- **§5 PRODUCTION_STANDARDS** : Controllers ≤ 200 lignes — dépassement **×25**
- **§1.1** : limite absolue 300 lignes — dépassement **×17**
- **§1.6 SOLID-S** : Single Responsibility Principle — 7 responsabilités distinctes dans 1 fichier
- **58 violations baseline PHPStan** (le plus gros contributeur du baseline)

C'est le **dernier gros bloc de dette technique de Phase B**. Toutes les autres extractions ont été faites (FileConversionService #79, AuthenticatedController, 11 batches baseline, 4 fixes sécurité). LMSDataController est l'éléphant restant.

---

## Objectifs

1. **Décomposer** le god object en 7 controllers SRP, chacun ≤ 600 lignes (cible idéale ≤ 300)
2. **Préserver** strictement le contrat HTTP existant (routes inchangées en URL, méthode, statut, payload)
3. **Migrer** chaque controller vers `AuthenticatedController` au passage (continuité Phase B)
4. **Détecter** les bugs latents pendant le refactor (les Batches 8 et 9 ont prouvé que les audits trouvent des typos cachés)
5. **Faciliter** les futurs audits, tests unitaires, et onboarding développeurs
6. **Réduire** le baseline PHPStan de ~58 violations sur ce fichier

---

## Inventaire complet (32 méthodes recensées)

### A. Classes (2 méthodes)
| Ligne | Méthode | Route |
|---|---|---|
| L53 | `classeDetails(int $classeId, Request $request)` | GET `/lms/classes/{classeId}` |
| L244 | `classeEtudiants(int $classeId, Request $request)` | GET `/lms/classes/{classeId}/etudiants` |

### B. Matières (3 méthodes)
| Ligne | Méthode | Route |
|---|---|---|
| L311 | `matiereDetails(int $matiereId, Request $request)` | GET `/lms/matieres/{matiereId}` |
| L3694 | `adminMatieresList(Request $request)` | GET `/admin/matieres` (role:admin,coordinateur) |
| L4391 | `myMatieres(Request $request)` | GET `/lms/teacher/my-matieres` (role:enseignant,coordinateur) |

### C. Enseignants (2 méthodes)
| Ligne | Méthode | Route |
|---|---|---|
| L3854 | `getEnseignants(Request $request)` | **PAS DE ROUTE** (code mort suspecté) |
| L4106 | `getEnseignantsFromKlassci(Request $request)` | GET `/lms/enseignants` |

### D. Séances (10 méthodes — séances générales + admin + actions globales)
| Ligne | Méthode | Route |
|---|---|---|
| L772 | `upcomingSeances(Request $request)` | GET `/lms/seances/upcoming` |
| L965 | `seanceParticipants(int $seanceId, Request $request)` | GET `/lms/seances/{seanceId}/participants` (1 of 2 conflicting) |
| L1080 | `validateParticipant(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/validate-participant` |
| L1505 | `seanceDetails(int $seanceId, Request $request)` | GET `/lms/seances/{seanceId}/details` |
| L2006 | `toggleVisioSeance(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/toggle-visio` (role:coordinateur,superAdmin) |
| L2179 | `myTeachingSeances(Request $request)` | GET `/lms/seances/my-teaching` (role:enseignant,coordinateur) |
| L2367 | `myClassesSeances(Request $request)` | GET `/lms/seances/my-classes` |
| L3579 | `hideSeance(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/hide` (role:etudiant) |
| L3632 | `unhideSeance(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/unhide` (role:etudiant) |
| L4672 | `getSeancesHistory(Request $request)` | GET `/lms/seances/history` (role:enseignant,coordinateur,superAdmin) |
| L4966 | `deleteSeance(..., int $seanceId)` | DELETE `/lms/seances/{seanceId}` (role:enseignant,coordinateur,superAdmin) |

### E. Visio (8 méthodes — actions visio en exclusivité)
| Ligne | Méthode | Route |
|---|---|---|
| L2562 | `activateVisio(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/activate-visio` (role:enseignant,coordinateur) |
| L2730 | `deactivateVisio(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/deactivate-visio` (role:enseignant) |
| L2791 | `startVisio(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/start-visio` (role:enseignant,coordinateur) |
| L2898 | `endVisio(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/end-visio` (role:enseignant,coordinateur) |
| L2965 | `joinVisio(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/join` (throttle:300) |
| L3058 | `getVisioParticipants(int $seanceId, Request $request)` | GET `/lms/seances/{seanceId}/participants` (2 of 2 conflicting) |
| L3449 | `leaveVisio(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/leave` (throttle:300) |
| L3517 | `heartbeatVisio(int $seanceId, ...)` | POST `/lms/seances/{seanceId}/heartbeat` (throttle:10000) |

### F. Attendances / Présences (3 méthodes)
| Ligne | Méthode | Route |
|---|---|---|
| L1355 | `syncAttendancesFromVideoSession(...)` | POST `/lms/attendances/from-video-session` |
| L4544 | `getAttendanceHistory(Request $request)` | GET `/lms/attendance/history` |
| L4801 | `getSeanceAttendances(Request $request, int $seanceId)` | GET `/lms/seances/{seanceId}/attendances` |

### G. Notifications préférences (2 méthodes)
| Ligne | Méthode | Route |
|---|---|---|
| L1455 | `getNotificationPreferences(int $userId, Request $request)` | GET `/lms/notifications/preferences/{userId}` |
| L2132 | `sendSessionReminder(...)` | POST `/lms/notifications/send-session-reminder` |

---

## Découpage cible (7 nouveaux controllers)

| Controller | Méthodes | Estimation taille | Path |
|---|---|---|---|
| **LMSClassesController** | 2 | ~270 lignes | `app/Http/Controllers/API/LMS/` |
| **LMSMatieresController** | 3 | ~620 lignes | `app/Http/Controllers/API/LMS/` |
| **LMSEnseignantsController** | 1 (getEnseignants supprimé) | ~290 lignes | `app/Http/Controllers/API/LMS/` |
| **LMSSeancesController** | 10 | ~1500 lignes ⚠️ | `app/Http/Controllers/API/LMS/` |
| **LMSVisioController** | 8 | ~1200 lignes ⚠️ | `app/Http/Controllers/API/LMS/` |
| **LMSAttendancesController** | 3 | ~400 lignes | `app/Http/Controllers/API/LMS/` |
| **LMSNotificationsPreferencesController** | 2 | ~700 lignes | `app/Http/Controllers/API/LMS/` |

⚠️ **`LMSSeancesController` (1500) et `LMSVisioController` (1200) dépasseront §5**. À sous-diviser plus tard si possible, mais d'abord faire le split principal puis tracker en follow-up. Actuel = 5014 lignes en 1 fichier. Après = 7 fichiers de taille gérable (max ~1500). Réduction immédiate du blast radius.

**LMSDataController résiduel** : supprimé entièrement à la fin (Phase C).

---

## Requirements EARS

### REQ-1 — Stricte préservation du contrat HTTP

**WHEN** chaque méthode est migrée vers son nouveau controller
**THEN** la route DOIT pointer vers le nouveau controller, en conservant strictement :
- Le path d'URL (ex : `/lms/seances/upcoming`)
- La méthode HTTP (GET/POST/DELETE)
- Le binding des paramètres (`{seanceId}`, `{classeId}`, etc.)
- Le middleware (`auth:sanctum + klassci.sync + role:*` + `throttle:*`)
- Le payload de retour (structure JSON identique)
- Le statut HTTP (200/201/403/404/500)
- Le nom de route (`->name('lms.seances.upcoming')`)

### REQ-2 — Migration vers `AuthenticatedController`

**WHEN** un controller est extrait
**THEN** il DOIT étendre `AuthenticatedController` (pas `Controller` direct)
**AND** tout appel `Auth::user()` / `Auth::id()` / `$request->user()` DOIT être migré vers `$this->authenticatedUser($request)` (continuité Phase B)

### REQ-3 — Nettoyage des dead-code révélés

**WHEN** le typage strict révèle des null-checks ou méthodes inexistantes (cf. leçons Batches 8/9/10)
**THEN** les corrections DOIVENT être appliquées dans le PR concerné, jamais masquées dans la baseline

### REQ-4 — Conflit de routes `/seances/{seanceId}/participants`

**WHEN** le split est implémenté
**THEN** le conflit actuel entre deux méthodes assignées à la même URL doit être résolu :
- `seanceParticipants` (L965) sur `LMSDataController` actuel
- `getVisioParticipants` (L3058) sur `LMSDataController` actuel
**BECAUSE** Laravel match dans l'ordre → seule la première est atteinte. La seconde est inaccessible.

Investigation requise en design : soit l'une est dead code, soit les routes doivent être différenciées (par préfixe ou query param).

### REQ-5 — Suppression du code mort `getEnseignants`

**WHEN** le split est implémenté
**THEN** `getEnseignants` (L3854) DOIT être :
- Soit supprimée si confirmée morte (aucune route, aucun appelant)
- Soit routée si réellement utilisée (à investiguer en design)

### REQ-6 — Tests Feature HTTP minimum

**WHEN** chaque controller extrait est livré
**THEN** au moins 2-3 tests Feature HTTP DOIVENT être ajoutés par controller :
- Au moins 1 happy path (auth OK, payload OK, status 200)
- Au moins 1 cross-tenant rejet (si applicable)
- Au moins 1 role-mismatch rejet (si applicable)

**BECAUSE** un fichier de 5014 lignes n'a actuellement aucune couverture HTTP. Tester chaque extraction prévient les régressions.

### REQ-7 — Migration progressive

**WHEN** la Phase B (extractions) est implémentée
**THEN** chaque extraction DOIT être un PR séparé avec son propre cycle de 3 audits
**BECAUSE** un seul PR de 5014 lignes serait impossible à reviewer

### REQ-8 — Phase C : suppression de LMSDataController

**WHEN** toutes les extractions sont mergées
**THEN** `LMSDataController.php` DOIT être supprimé entièrement
**AND** son import retiré de `routes/api.php`

### REQ-9 — Pas de régression cross-tenant

**WHEN** chaque méthode migrée utilise `User::isAdmin()` ou check role
**THEN** le pattern correct (cf. fixes #87/#91/#98/#102/#103) DOIT être appliqué :
- `supradmin` bypass cross-tenant légitime
- `admin/administrateur/superAdmin` intra-tenant (check `institution_id`)
- Si trait `ChecksFileAuthorization` ou similaire est pertinent, le réutiliser

Ce REQ peut générer des PRs sécurité séparés (comme #87 → #96 lors des batchs) si des bugs cross-tenant sont découverts.

### REQ-10 — Documentation finale

**WHEN** le split est terminé
**THEN** `docs/REFACTORING_ROADMAP.md` (ou équivalent) DOIT être mis à jour pour refléter que ce TIER 1 est complet
**AND** un éventuel `docs/LMS_CONTROLLERS_OVERVIEW.md` peut être créé pour orienter les futurs développeurs vers le bon controller

---

## Non-objectifs (hors scope)

- Réécrire la logique métier (sauf bugs latents révélés par audits)
- Refactor des dépendances (`KlassciProxyService`, etc.) — déjà fait dans d'autres PRs
- Tests unitaires exhaustifs (les tests Feature HTTP suffisent pour ce refactor)
- Conformité §5 stricte (1500 lignes pour SeancesController acceptable comme étape intermédiaire — sous-split en follow-up)
- Migration vers Repository pattern (architecture séparée, autre projet)
- Migration vers Service Layer (idem)
- Création de Laravel Policies (idem)

---

## Hypothèses critiques

1. Les routes existantes sont en production (ne pas casser leur contrat est critique)
2. Le frontend (`lms-frontend`) appelle ces routes par URL, pas par référence controller PHP → renommer le controller est safe pour le frontend
3. Les sub-controllers pourront être placés dans `app/Http/Controllers/API/LMS/` (sub-namespace `\App\Http\Controllers\API\LMS\`)
4. `BelongsToInstitution` est appliqué globalement sur les modèles concernés (Classe, Matiere, Seance, etc.) — cohérent avec audits précédents
5. Les 32 méthodes ne se référencent pas entre elles (pas d'appel `$this->autreMethode()` cross-responsabilité)

---

## Risques

| # | Risque | Mitigation |
|---|---|---|
| R1 | Effet de bord de couplage interne (méthodes qui s'appellent l'une l'autre) | Audit explicite en Phase 1 design : `grep '\$this->' app/Http/Controllers/API/LMSDataController.php` |
| R2 | Routes en conflit (cf. REQ-4) | Investigation explicite en design + résolution dans 1 PR dédié |
| R3 | Bugs latents (typos, dead code) découverts en cours | Workflow §A obligatoire pour chaque PR — audits attraperont, comme prouvé Batches 8/9 |
| R4 | Tests Feature HTTP cassent en CI (db migrations, fixtures) | Skip `pdo_pgsql` pattern déjà utilisé dans #91/#95/#100/#102/#103 |
| R5 | Estimation effort sous-évaluée | Cible 1 PR / jour × 7 = 1 semaine ouvrable. Si plus, c'est OK — pas d'urgence (Phase B baseline est l'unique chantier restant) |
| R6 | Régression cross-tenant si méthodes utilisent `isAdmin()` ambigu | REQ-9 : audit chaque méthode au passage. Si patterns identiques #87/#91/#98/#102/#103 trouvés, PR sécurité séparé |

---

## Acceptance criteria

- [ ] 7 nouveaux controllers créés dans `app/Http/Controllers/API/LMS/`
- [ ] Chaque controller étend `AuthenticatedController`
- [ ] Toutes les routes (`routes/api.php`) repointées vers les nouveaux controllers
- [ ] Aucune route cassée (vérifié par `php artisan route:list --path=lms` diff vide en URLs/methods)
- [ ] `LMSDataController.php` supprimé en Phase C
- [ ] PHPStan 0 errors hors baseline + baseline réduite d'environ -58 entrées
- [ ] Tests Feature HTTP minimum par controller (REQ-6)
- [ ] Aucun `User::isAdmin()` ambigu introduit (audit chaque migration)
- [ ] Tous les PRs ont 3 audits PASS (security strict obligatoire)
- [ ] REQ-4 (conflit routes) résolu et documenté
- [ ] REQ-5 (dead code `getEnseignants`) traité
- [ ] `docs/SECURITY_CI.md` ou équivalent mis à jour avec entrée historique

---

## Plan de phases

### Phase 1 — Spec workflow (cette spec)
- **1.1** ✓ Inventaire des 32 méthodes
- **1.2** ✓ Mapping méthodes → routes
- **1.3** ⏳ requirements.md (this document)
- **1.4** ⏳ design.md (architecture cible, ordre de migration, dépendances)
- **1.5** ⏳ tasks.md (checkboxes granulaires)
- **1.6** Approbation user

### Phase 2 — Extraction par responsabilité (7 PRs)
Ordre proposé (du plus simple au plus complexe) :
1. **PR A** : `LMSClassesController` (2 méthodes, ~270 lignes)
2. **PR B** : `LMSEnseignantsController` (1-2 méthodes + cleanup getEnseignants)
3. **PR C** : `LMSAttendancesController` (3 méthodes, ~400 lignes)
4. **PR D** : `LMSMatieresController` (3 méthodes, ~620 lignes)
5. **PR E** : `LMSNotificationsPreferencesController` (2 méthodes, ~700 lignes)
6. **PR F** : `LMSSeancesController` (10 méthodes, ~1500 lignes — gros)
7. **PR G** : `LMSVisioController` (8 méthodes, ~1200 lignes — gros) + résolution REQ-4 conflit routes

### Phase 3 — Cleanup final (1 PR)
- **PR H** : Suppression `LMSDataController.php` + nettoyage import `routes/api.php` + docs

---

## Justification (Q11/Q14)

Per `feedback_best_not_fastest` (mémoire utilisateur) : **« Meilleure solution architecturale > rapidité. 10 fichiers au lieu de 2 si l'architecture le demande »**.

Per `PRODUCTION_STANDARDS.md §1.1 + §5` : un controller de 5014 lignes viole les règles dures du projet (×17 et ×25 respectivement).

Per le précédent **Issue #79 FileConversionService split** (586 lignes monolithique → 5 services SRP + façade, livré en 3 PRs) : le pattern de split est éprouvé sur ce projet.

Per la session courante : 11 batches refactoring + 4 fixes sécurité ont posé les bases (`AuthenticatedController`, traits `ChecksForumAuthorization` / `ChecksFileAuthorization`, pattern de spec workflow §A) qui rendent ce split efficace.

**Coût d'inaction** : sans ce split, chaque future feature qui touche LMSData aggrave la dette exponentiellement.

**Bénéfice à 10 ans** : code base auditable, testable, onboarding facilité, conformité §1.1 et §5.

---

## Effort estimé (révisé après inventaire)

- Phase 1 (spec) : 1 jour (en cours)
- Phase 2 (7 PRs extractions) : ~1 PR/jour = **7 jours ouvrables**
- Phase 3 (cleanup) : 0.5 jour

**Total : ~2 semaines ouvrables** (vs estimation initiale 1-2 jours = sous-évalué). C'est cohérent avec un refactor de cette ampleur.
