# Requirements — #608 · Divergences SQLite/MySQL : présences vidéo + dashboard étudiant

## 0. Correction de l'énoncé de l'issue (vérifiée, pas supposée)

L'issue #608 attribue les 5 échecs à `ONLY_FULL_GROUP_BY`. **C'est faux, et prouvé faux :**

```
grep -rn "groupBy\|GROUP BY" app/Services/Dashboard/ app/Services/Attendances/ \
     app/Http/Controllers/API/Dashboard/ app/Http/Controllers/API/LMS/
→ 0 occurrence
```

Aucune requête des deux chemins n'a de `GROUP BY` : `ONLY_FULL_GROUP_BY` ne peut
structurellement pas être en cause. Les 5 échecs ont **deux causes racines distinctes**,
toutes deux invisibles sous SQLite, identifiées par exécution réelle sous `mysql:8.4`
(`sql_mode` serveur ET session = `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,
NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`, posé par
`config/database.php:59 'strict' => true`).

Ce document remplace l'hypothèse de l'issue par les causes mesurées.

---

## 1. Cause A — dashboard étudiant : schéma fantôme (BUG DE PRODUCTION RÉEL)

### Preuve d'exécution

```
GET /api/dashboard/student  →  500
Illuminate\Database\QueryException
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'percentage' in 'field list'
SQL: select avg(`percentage`) as aggregate from `quiz_attempts`
     where `user_id` = 1 and `status` = completed
     @ app/Services/Dashboard/StudentDashboardService.php:187
```

### Deux références fantômes sur les mêmes lignes (`StudentDashboardService.php:181-187`)

| # | Référence | Réalité du schéma (`DESCRIBE quiz_attempts`) | Effet SQLite | Effet MySQL |
|---|---|---|---|---|
| A1 | colonne `percentage` | **n'existe pas**. Le pourcentage est `score` `decimal(5,2)`, écrit par `QuizGradingService.php:177` (`($pointsEarned / $pointsPossible) * 100`) et `:229` | littéral `'percentage'` → `avg()` d'une chaîne → `0` silencieux | **`1054` → HTTP 500** |
| A2 | valeur `status = 'completed'` | `enum('in_progress','submitted','graded','abandoned')` — `completed` **n'en fait pas partie** | 0 ligne, silencieux | 0 ligne, silencieux |
| A3 | même valeur fantôme dans `upcomingQuizzes()` (`:140`, sous `whereDoesntHave('attempts', …)`) | idem | exclusion MORTE → un quiz déjà passé reste annoncé « à venir » | idem |

A3 a été trouvé par la **revue de code**, pas par les tests : aucun des 5 tests d'acceptation
ne l'exerce (l'étudiant y est vide) et il ne produit aucune erreur SQL. Prouvé par un test
écrit avant correctif (`upcoming_quizzes` renvoyait `['Quiz déjà passé', 'Quiz à faire']`
au lieu de `['Quiz à faire']`).

Conséquence en production (MySQL) : **le dashboard étudiant renvoie 500 pour tout étudiant
authentifié** — la panne est totale, pas dégradée. A2 rend en plus `total_attempts` et
`average_score` structurellement inertes (toujours 0) sur les deux moteurs.

### Référence interne faisant autorité

`TeacherDashboardService::completedAttemptsQuery()` (#364,
`app/Services/Dashboard/TeacherDashboardService.php:198-203`) définit déjà la sémantique
correcte du même concept métier :

```php
->whereIn('status', ['submitted', 'graded'])   // tentative terminée par l'étudiant
```

et agrège `avg('score')`. Elle est **prouvée par un test vert** :
`tests/Unit/Services/Dashboard/TeacherDashboardServiceTest.php:206-246`
(`total_attempts = 3`, `average_score = 70.0`). Le dashboard étudiant doit dire la même
chose que le dashboard enseignant sur la même donnée.

---

## 2. Cause B — présences vidéo : identifiant auto-incrémenté codé en dur dans les tests

### Preuve d'exécution

Même test, même code applicatif, deux moteurs (3 tests successifs créant chacun une séance
sous `RefreshDatabase`) :

```
MySQL 8.4 : seance.id = 1  →  2  →  3
SQLite    : seance.id = 1  →  1  →  1
```

`RefreshDatabase` enveloppe chaque test dans une transaction annulée en fin de test. Or :

- **InnoDB** : le compteur `AUTO_INCREMENT` est maintenu **hors transaction** ; un `ROLLBACK`
  ne le restaure pas (MySQL 8.4 Reference Manual, §17.6.1.6 *AUTO_INCREMENT Handling in
  InnoDB* : « the counter is not rolled back … values assigned may be lost, producing gaps »).
- **SQLite** : sans le mot-clé `AUTOINCREMENT`, le `rowid` vaut `max(rowid)+1` — après
  annulation la table est vide, la valeur `1` est donc **réattribuée** (SQLite docs,
  *ROWIDs and the INTEGER PRIMARY KEY*).

Les tests postent `'seance_cours_id' => 1` **en dur** alors qu'ils créent leur propre séance
en `setUp()`. Dès le 2ᵉ test du processus, l'id réel n'est plus 1 :
`VideoSessionAttendancesSyncer::resolveSeance()` (`id = 1 OR klassci_seance_id = 1`) ne
trouve rien → `fail(404, 'seance_not_found')` → réponse non-2xx.

Vérification croisée : chacun de ces tests **passe en isolation** (`--filter`) et **échoue**
dans son fichier complet. Le code applicatif n'est pas en cause.

### Conséquence

**Aucun bug de production sur ce chemin.** C'est un défaut de portabilité de la
**suite de tests** : elle encode une garantie que seul SQLite offre. Le nier serait masquer
le vrai risque — une suite qui ne dit pas la vérité sur le moteur de production.

`enrolledUserIds()`, désigné comme suspect dans l'énoncé de la tâche, est **hors de cause**
(vérifié : requêtes correctes, aucun `GROUP BY`, aucune colonne fantôme).

---

## 3. Conformité `STRICT_TRANS_TABLES`

Vérifiée par exécution, pas par lecture : l'`INSERT INTO esbtp_attendance` du chemin
présences (15 colonnes, `status` ∈ {`connected`,`disconnected`}) s'exécute sans troncature
sous `STRICT_TRANS_TABLES`. Aucune correction requise.

---

## 4. Exigences (EARS)

- **R1** — WHEN un étudiant authentifié appelle `GET /api/dashboard/student` sous MySQL en
  mode strict, THEN le système SHALL répondre `200` avec l'enveloppe `{success, data}`
  inchangée (aucune rupture de contrat client).
- **R2** — WHERE le dashboard étudiant agrège les tentatives de quiz, THEN il SHALL
  n'utiliser que des colonnes et des valeurs d'énumération **réellement présentes au
  schéma**, et SHALL produire les mêmes chiffres que `TeacherDashboardService` sur le même
  jeu de tentatives.
- **R3** — WHERE la définition « tentative de quiz terminée » est utilisée **par les
  services Dashboard**, THEN elle SHALL exister en **un seul endroit** du code ; ni
  `StudentDashboardService` ni `TeacherDashboardService` ne SHALL redéclarer la liste
  littérale des statuts.
  _Portée volontairement bornée aux dashboards_ : le sweep a trouvé **5 autres** sites
  déclarant le même littéral `['submitted', 'graded']` — `QuizAccessService.php:62,100,137`
  et `QuizStatisticsService.php:39,43`. Ils sont **corrects** (même sémantique, documentée
  par le fix E2E #211), donc hors des deux chemins fautifs de #608 : les convertir serait
  un élargissement de périmètre sur du code sain. Voir §5 (dette tracée).
- **R4** — WHEN la suite de tests des présences vidéo s'exécute, THEN elle SHALL cibler la
  séance qu'elle a elle-même créée, et SHALL NOT supposer une valeur d'`AUTO_INCREMENT`.
- **R5** — Les 5 tests d'acceptation SHALL passer sous **MySQL 8.4** ET sous **SQLite**,
  dans un même processus (ordonnancement réel de la suite), sans modification de
  `phpunit.xml` ni assouplissement de `'strict' => true`.
- **R6** — L'invariant anti-N+1 de `VideoSessionAttendancesSyncer` (#503) SHALL rester
  vérifié : nombre de requêtes de résolution constant de 2 à 5 participants.
- **R7** — La correction SHALL être couverte par un test qui **échoue sur le code d'avant**
  (les tests d'acceptation existants n'exercent pas les agrégats : l'étudiant y est vide).

## 5. Hors périmètre (dettes constatées, non corrigées ici)

- `AdminDashboardService.php:128` — même filtre fantôme `status = 'completed'`, mais
  **délibérément figé** par un test de caractérisation
  (`AdminDashboardServiceTest::test_completed_attempts_counter_stays_zero_under_current_schema`,
  finding #364). Le corriger casserait un test qui documente la dette : décision du
  mainteneur, pas de #608.
- `QuizNotificationDispatcher.php:66,73,91` — lit `$attempt->percentage` **et**
  `$attempt->max_score` (attributs inexistants → `null`, pas d'erreur SQL : le message rend
  « note de 80.00/ »), et `:91` refiltre `status = 'completed'` → les rappels d'échéance
  partent aussi aux étudiants ayant déjà terminé. Même famille, chemin notifications : hors
  des 2 chemins de #608.
- `StudentDashboardService.php:151-152` — `upcoming_quizzes[].questions_count` et
  `.time_limit_minutes` : **ni colonne, ni accessor, ni append** sur `Quiz` (les colonnes
  réelles sont `total_questions` et `duration_minutes`) → toujours `null` dans le payload.
  Déjà connu (`phpstan-baseline.neon` : « Access to an undefined property
  `Quiz::$time_limit_minutes` »). Non corrigé ici : c'est une lecture d'attribut PHP, pas
  une requête — aucun 500 — et changer ces valeurs (`null` → nombre) modifie le contenu
  d'une réponse consommée, ce qui mérite son propre arbitrage.
- `SystemMetricsService.php:175,241` — `whereNotNull('completed_at')` sur une colonne qui
  existe mais n'est **jamais écrite** (aucun writer trouvé dans `app/`) → compteur inerte.
- **Duplication résiduelle du littéral `['submitted', 'graded']`** :
  `QuizAccessService.php:62,100,137` et `QuizStatisticsService.php:39,43`. Code **sain**,
  mais 5 redéclarations d'un concept qui a désormais une définition unique
  (`QuizAttempt::scopeCompleted()`). Refactor mécanique à faire dans une PR dédiée — c'est
  cette duplication qui a permis à #608 de dériver, la solder est une prévention réelle.

Ces quatre points sont signalés à l'orchestrateur pour arbitrage (issue de suivi).
