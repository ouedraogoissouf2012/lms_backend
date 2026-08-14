# Tasks — #544 [P2][SECURITY] PII : email d'autrui exposé via GET /forum/topics

- [x] 1. Test RED : `tests/Feature/Security/ForumEmailPiiLeakTest.php`
  - `index`, `show`, `store` (topic), `storePost` : créer un topic/post avec un
    auteur d'email distinctif, appeler en tant qu'un autre utilisateur, asserter
    que l'email n'apparaît PAS dans le corps JSON brut, et que `id`/`name`/`role`
    sont présents.
  - Confirmé RED avant le fix (email présent dans les 4 réponses).
  - _Requirements: R1, R2, R3_

- [x] 2. GREEN : retirer `email` des 5 `select` partiels cités par l'issue
  - `app/Services/Forum/ForumTopicService.php` (lignes 46, 107, 120, 124×2)
  - `app/Services/Forum/ForumPostService.php` (ligne 59)
  - _Requirements: R1_

- [x] 3. Non-régression : suite Forum complète + PHPStan sur les 2 fichiers
  - 1ère passe : PHPStan lancé par erreur sur le fichier de test (hors périmètre,
    `phpstan.neon.dist` ne couvre que `app/`) — corrigé, re-scopé à `app/`.

- [x] 4. Audits `spec-security` + `spec-architect` en parallèle (CONTRIBUTING.md §A)
  - Sécurité : **FAIL initial** — finding CRITICAL réel et vérifié (3 sites
    `fresh(['user'])` non restreints, `update()`×2 + `markAsSolution()`,
    exploitable cross-utilisateur via l'autorisation par propriétaire du
    TOPIC plutôt que du POST). Corrigé (tâche 4bis) puis re-vérifié.
  - Architecture : PASS avec 1 finding MEDIUM (duplication de la chaîne
    littérale `user:id,name,role` à 8 endroits) — corrigé via constante
    `AUTHOR_COLUMNS` partagée par service.

- [x] 4bis. Correctifs post-audit
  - 3 sites `fresh(['user'])` → `fresh([self::AUTHOR_COLUMNS])`
  - Constante `AUTHOR_COLUMNS` introduite dans les 2 services (8 sites au
    total réunifiés)
  - 3 nouveaux tests : `update` topic (admin), `update` post (admin),
    `markAsSolution` (propriétaire du topic marquant la réponse d'un AUTRE
    étudiant) — reproduisent exactement le scénario CRITICAL de l'audit
  - Suite Forum complète re-exécutée : 49 passed. PHPStan (scope `app/`
    correct) : 0 erreur.

- [ ] 5. PR vers `lms`
