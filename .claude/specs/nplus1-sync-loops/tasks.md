# Tasks — Éliminer les N+1 des boucles de sync (#503)

Ordre TDD : RED (test anti-N+1) → GREEN → non-régression → vérif. On traite P1
d'abord (testé), puis P2 (test neuf).

## 1. P1 — attendances (RED → GREEN)

- [ ] **1.1** `tests/Feature/Performance/AttendancesSyncNoNPlusOneTest.php` (NEW) :
  monter une séance + classe + N étudiants inscrits ; `POST /api/lms/attendances/from-video-session`
  avec 2 participants puis 5 participants ; `DB::enableQueryLog()` → **même compte
  de requêtes** (setup hors mesure). _(REQ-7, AC1.)_ **Doit échouer** aujourd'hui.
- [ ] **1.2** `VideoSessionAttendancesSyncer` : précharger `usersByKlassciId`
  (1 requête `whereIn`), ajouter `enrolledUserIds()` (2 requêtes fixes), réécrire
  `resolveStudents` en mémoire (index + tout-ou-rien préservés), **supprimer**
  `isEnrolled`. _(REQ-1/2/3.)_
- [ ] **1.3** `SyncAttendancesRequest` : `participants` → `max:100`. _(REQ-4.)_
- [ ] **1.4** `SyncAttendancesRequestTest` : + test `participants` > 100 → 422.
- [ ] **1.5** Lancer 1.1 + `SyncAttendancesRequestTest` → **GREEN**.

## 2. P2 — résultats éval (RED → GREEN)

- [ ] **2.1** `tests/Feature/Performance/EvaluationResultsNoNPlusOneTest.php` (NEW) :
  mock `KlassciProxyService` renvoyant un roster de N étudiants ; éval + submissions ;
  `GET /api/evaluations/{id}/results-by-class` avec N=2 puis N=5 → **même compte**
  de requêtes. _(REQ-7, AC4.)_ **Doit échouer** aujourd'hui.
- [ ] **2.2** `buildResultats` : précharger `usersByEmail` (1 requête) +
  `submissionsByKlassci` (`latest()->get()->groupBy`), résoudre en mémoire (2
  stratégies inchangées). _(REQ-5/6/8.)_
- [ ] **2.3** Test de correctness P2 (dans le même fichier ou
  `EvaluationResultsCorrectnessTest`) : un étudiant avec submission live → bon
  résultat rattaché ; fallback id KLASSCI ; exclusion `[PRACTICE]`. _(REQ-8, AC5.)_
- [ ] **2.4** Lancer 2.1 + 2.3 → **GREEN**.

## 3. Non-régression

- [ ] **3.1** `php artisan test tests/Feature/LMS/Attendances/ tests/Feature/Requests/SyncAttendancesRequestTest.php tests/Feature/Evaluation/`
  → 100 % (created/updated/errors P1, résultats P2 identiques).
- [ ] **3.2** Vérifier la relation `Classe::etudiants` + pivot `classe_etudiant.statut`
  (utiliser la même forme que le code actuel `where('classe_etudiant.statut','actif')`).

## 4. Vérification

- [ ] **4.1** PHPStan level 9 sur les 3 fichiers → 0 erreur (attention aux types
  Collection/keyBy, `mixed` des payloads KLASSCI → `KlassciPayload` si besoin).
- [ ] **4.2** Garde tailles : `sync`/`resolveStudents`/`enrolledUserIds`/
  `buildResultats` ≤40 ; services ≤300.
- [ ] **4.3** Grep : plus aucun `User::query()...->first()` ni `exists()` dans une
  boucle de ces 2 services.

## 5. Clôture

- [ ] **5.1** Après merge PR : fermer #503 + cocher la case dans l'épique #496.

## Traçabilité exigences → tâches

| Exigence | Tâche(s) |
|---|---|
| REQ-1 (P1 users) | 1.1, 1.2 |
| REQ-2 (P1 enrollment) | 1.1, 1.2 |
| REQ-3 (tout-ou-rien) | 1.2, 3.1 |
| REQ-4 (max) | 1.3, 1.4 |
| REQ-5 (P2 users) | 2.1, 2.2 |
| REQ-6 (P2 submissions) | 2.1, 2.2 |
| REQ-7 (compte constant) | 1.1, 2.1 |
| REQ-8 (sortie inchangée) | 2.3, 3.1 |
