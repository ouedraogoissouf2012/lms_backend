# Tasks — #548 [P2] per_page/limit non bornés + throttle manquant

Ordre : un endpoint à la fois, TDD strict (RED prouvé → GREEN), pour limiter le
risque et garder chaque commit revuable indépendamment de la taille totale.

- [ ] 1. `GET /api/evaluations` — `ListEvaluationsRequest` (`limit`, max 100)
  - Test RED : `limit=101` → aujourd'hui pas d'erreur (paramètre ignoré) ; après
    fix → `422`. Test non-régression : réponse reste un tableau plat
    (`assertJsonStructure` sans clé `current_page`/`data.*`).
  - GREEN : `ListEvaluationsRequest`, type-hint sur `EvaluationCrudController::index`,
    `EvaluationListService::listForTeacher` reçoit `limit` et fait `->limit()->get()`.
  - _Requirements: R1, R2, R3_

- [ ] 2. `GET /lms/attendance/history` — `AttendanceHistoryRequest`
  - RED : `per_page=101` → aujourd'hui accepté ; après fix → `422`.
  - GREEN : FormRequest + type-hint `LMSAttendancesController::getAttendanceHistory`.
  - _Requirements: R1, R2_

- [ ] 3. `GET /lms/seances/history` — `SeancesHistoryRequest`
  - Même schéma que tâche 2, sur `LMSSeancesHistoryController::getSeancesHistory`.
  - _Requirements: R1, R2_

- [ ] 4. `GET /notifications` + `GET /notifications/recent` — `ListNotificationsRequest` + `RecentNotificationsRequest`
  - RED : `per_page=101` sur `/notifications`, `limit=21` sur `/recent`.
  - GREEN : 2 FormRequest, type-hint sur `NotificationsController::index` et `::recent`.
  - _Requirements: R1, R2_

- [ ] 5. `GET /api/quizzes` — `ListQuizzesRequest`
  - Vérifier d'abord (lecture code) si `QuizCrudService::list()` lit d'autres
    clés de `$filters` au-delà de `per_page` — si oui, `rules()` doit les
    laisser passer (`sometimes|string`) pour ne rien casser.
  - RED puis GREEN, type-hint `QuizCrudController::index`.
  - _Requirements: R1, R2_

- [ ] 6. `GET /api/forum/topics` — `ListForumTopicsRequest`
  - RED puis GREEN, type-hint `ForumController::index`.
  - _Requirements: R1, R2_

- [ ] 7. `GET /api/search` — `GlobalSearchRequest` (fusionne la règle `query` déjà inline)
  - RED : `limit=21` → aujourd'hui accepté ; après fix → `422`.
  - GREEN : FormRequest, type-hint `SearchController::globalSearch`, suppression
    du `$request->validate([...])` inline désormais dupliqué.
  - _Requirements: R1, R2_

- [ ] 8. Throttle recherche
  - RED : 31 requêtes rapides sur `/api/search` → aujourd'hui jamais de `429`.
  - GREEN : `RateLimiter::for('search', ...)` dans `RateLimitServiceProvider`,
    `throttle:search` sur le groupe `routes/api/admin.php:118`.
  - _Requirements: R4_

- [ ] 9. Non-régression : suite complète locale (leçon #545 — tout état partagé
      entre tests doit être vérifié, pas supposé propre) + PHPStan level 9 sur
      tous les fichiers touchés.

- [ ] 10. Audits `spec-security` + `spec-architect` en parallèle (CONTRIBUTING.md §A)

- [ ] 11. PR vers `lms`
