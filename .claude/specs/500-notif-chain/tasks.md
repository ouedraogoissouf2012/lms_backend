# Tasks — #500 · Câbler le quiz, retirer la chaîne Leçon/Forum redondante

- [x] **1. Établir le constat par exécution** _(Requirements: §0)_
  - [x] 1.1 Grep des 7 méthodes + des 3 dispatchers → confirmer 0 appelant réel
  - [x] 1.2 Vérifier que leçon et forum émettent bien ailleurs (job + inline)
  - [x] 1.3 Confirmer que #609 est mergée dans `lms` (`QuizAttempt::scopeCompleted` disponible)

- [x] **2. Tests AVANT tout code** _(Requirements: R1, R2, R3, R6)_
  - [x] 2.1 `test_manual_grading_notifies_the_student` → RED (aucune notification)
  - [x] 2.2 `test_grade_notification_carries_real_points_and_percentage` → RED (« /  », null)
  - [x] 2.3 `test_publishing_a_quiz_notifies_the_class` → RED
  - [x] 2.4 `test_deadline_reminder_skips_students_who_already_finished` → RED (B3)
  - [x] 2.5 `test_deadline_command_does_not_notify_twice_the_same_day` → RED
  - [x] 2.6 `test_deadline_command_sets_institution_on_notifications` → RED

- [x] **3. Corriger le dispatcher quiz avant de le câbler** _(Requirements: §2)_
  - [x] 3.1 B1/B2 : `max_score` → `points_possible`, `percentage` → `score` (clés `data` conservées)
  - [x] 3.2 B3 : `where('status','completed')` → `->completed()` (définition unique de #609)

- [x] **4. Câbler — une ligne d'émission par service** _(Requirements: R1, R2)_
  - [x] 4.1 `QuizAttemptTeacherGradeService` : DI + appel `notifyGradeReceived`
  - [x] 4.2 `QuizCrudService::publish()` : DI + appel `notifyQuizAvailable`
  - [x] 4.3 Sweep `grep -rn "new Quiz(Crud|AttemptTeacherGrade)Service("` — les constructeurs changent

- [x] **5. `NotifyQuizDeadlines`** _(Requirements: R3, R6)_
  - [x] 5.1 Commande calquée sur `NotifyUpcomingEvaluations` + tenant restauré par institution
  - [x] 5.2 Garde d'idempotence journalière
  - [x] 5.3 Enregistrement dans `routes/console.php`

- [x] **6. Retraits** _(Requirements: R4, R5, R7)_
  - [x] 6.1 Supprimer `LessonNotificationDispatcher` + `ForumNotificationDispatcher`
  - [x] 6.2 Retirer les 4 méthodes façade + les 2 dépendances constructeur
  - [x] 6.3 Purger les entrées `phpstan-baseline.neon` des fichiers supprimés
  - [x] 6.4 Vérifier : plus aucune méthode de `NotificationService` sans appelant

- [x] **7. Validation**
  - [x] 7.1 Nouveaux tests VERTS — **11 tests** (6 de câblage + 5 issus de la revue), tous rouges avant
  - [ ] 7.2 Non-régression : `tests/Feature/Forum`, `tests/Feature/Lesson`, `tests/**/Quiz*`, `tests/**/Notification*`
  - [x] 7.3 PHPStan → 0 erreur, **15 entrées de baseline mortes purgées** (11 des fichiers supprimés + `max_score`/`percentage`/`status` + la nullabilité `$user`, dont la disparition PROUVE le défaut C1)
  - [x] 7.4 Tailles : services ≤300, méthodes ≤40

- [ ] **8. Revue et livraison**
  - [x] 8.1 `/code-review` (repli, le skill thermo-nuclear est absent de la session) → 8 findings : **6 corrigés** (C1-C6), 2 écartés avec justification (requirements §3bis)
  - [ ] 8.2 `git add -f .claude/specs/500-notif-chain/`
  - [ ] 8.3 Commit conventionnel avec `(#500)` en fin de titre — **après accord du user**
  - [ ] 8.4 PR vers `lms`, reporter le n° à l'orchestrateur
