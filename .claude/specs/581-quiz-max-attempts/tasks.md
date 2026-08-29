# #581 — Tâches

- [ ] 1.1 Test RED : `max_attempts = 1`, trois `POST /start` → une seule tentative
      en base, les 2ᵉ et 3ᵉ rendent la MÊME (200). _Requirements: R1.1_
- [ ] 1.2 Test RED : la reprise conserve `started_at` et un `time_remaining`
      décroissant, jamais remis à neuf. _Requirements: R1.2_
- [ ] 1.3 Test RED : après reprise, la première soumission passe et la seconde
      tombe en 422. _Requirements: R1.1_
- [ ] 2.1 Test RED : `user_can_attempt` reste vrai avec une tentative reprenable à
      `max_attempts = 1`, et `user_attempts_count` la compte. _Requirements: R2.1, R2.2_
- [ ] 2.2 Test RED : une tentative `abandoned` ne consomme pas de quota.
      _Requirements: R2.1_
- [ ] 3.1 Test RED de course déterministe : insertion concurrente intercalée →
      reprise (200) si la gagnante est ouverte, 409 sinon, jamais 500. _Requirements: R3.1_
- [ ] 4.1 Test RED : tentative héritée à `institution_id = NULL` (vrai jeton Bearer,
      tenant réellement résolu) → pas de 409. _Requirements: R4.1_
- [ ] 5.1 `QuizAccessService` : `attemptKeyspace()` non scopé, `activeAttemptForUser()`,
      comptage hors `abandoned`, `canUserAttempt()` incluant la reprise.
- [ ] 5.2 `QuizAttemptStartSubmitService::startAttempt()` : reprise → quota →
      insertion sous garde → conflit.
- [ ] 6.1 Suite impactée verte + PHPStan level 9 à 0 + revue de code, puis PR.
