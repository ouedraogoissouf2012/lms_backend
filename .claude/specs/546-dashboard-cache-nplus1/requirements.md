# Requirements — N+1 DB + dashboard admin non caché (#546)

## Contexte & preuves

Sous-issue de l'épique #535 (audit 2026-08-26). Trois sites distincts, un seul
correctif transversal (précharger / agréger en amont, cacher les agrégats
coûteux). Le 4ᵉ site cité par l'issue (`TeacherEvaluationResultsService:141-168`)
est **déjà corrigé** par le commit `a4baf937` (#503, mergé sur `lms` avant cette
branche) — vérifié par lecture du fichier courant : `buildResultats` précharge
déjà `usersByEmail`/`submissionsByKlassci`. **Hors scope ici**, aucune régression
à couvrir dessus.

### Site 1 — `AdminDashboardService::buildStats` non caché
`app/Services/Dashboard/AdminDashboardService.php:51-109` — ~10 `count()`
Eloquent exécutés à **chaque** appel de `GET /dashboard/stats`, alors que les
services sœurs du même dashboard (`SystemMetricsService`, `ActivityTrendsService`
— `app/Services/AdminAnalytics/*`) cachent leurs agrégats 300s avec une clé
scopée tenant. Incohérence de charge + de fraîcheur entre les deux jeux de
stats du même écran admin.

### Site 2 — `QuizCrudService::list` (transform post-pagination)
`app/Services/Quiz/QuizCrudService.php:113-119` : pour **chaque** quiz de la
page paginée, le `transform()` appelle `QuizAccessService::attemptsCountForUser`
(1 requête), `canUserAttempt` (qui **rappelle** `attemptsCountForUser` → requête
dupliquée) et `bestAttemptForUser` (1 requête) — jusqu'à 3 requêtes/quiz. Pour
`per_page=15`, jusqu'à ~45 requêtes rien que pour l'enrichissement utilisateur.

### Site 3 — `MyMatieresQueryService::enrichMatiere`
`app/Services/Matiere/MyMatieresQueryService.php:81-116` : pour **chaque**
matière du dashboard enseignant, 3 requêtes DB (`Lesson::published()->count()`,
`Lesson::where('status','draft')->count()`, `Seance::count()`) — le compte
`nombre_evaluations` est déjà calculé en mémoire (pas de requête). N matières
KLASSCI → 3×N requêtes.

## Portée

- **IN** : cache TTL 300s scopé tenant sur `AdminDashboardService::buildStats` ;
  batching 1-requête pour les compteurs de tentatives quiz sur la page paginée ;
  batching 3-requêtes (au lieu de 3×N) pour les stats matières.
- **OUT** : `TeacherEvaluationResultsService` (déjà fait, #503) ; le contenu
  fonctionnel des payloads (aucun champ ajouté/retiré) ; l'invalidation active du
  cache dashboard sur écriture (le TTL 300s suffit, cohérent avec le précédent
  `SystemMetricsService`/`ActivityTrendsService` qui n'en ont pas non plus) ;
  `TenantScopedCache` (no-op sur store `database`, périmètre #547).

## Exigences (EARS)

**REQ-1 — Cache dashboard admin scopé tenant**
THE SYSTEM SHALL mettre en cache le payload de `AdminDashboardService::buildStats`
pendant 300s, avec une clé dérivée du **slug d'institution** résolu
(`TenantManager::getResolvedSlug()`) — jamais `md5(klassci_tenant_url)` seul
(régression historique documentée sur `SystemMetricsService`).

**REQ-2 — Sous-scope `klassci_tenant_url` du coordinateur**
THE SYSTEM SHALL inclure dans la clé de cache un second segment dérivé de
`$user->klassci_tenant_url` (ou un marqueur `all` si absent), car `buildStats`
varie **par coordinateur** au sein d'une même institution (filtre `when()`
verrouillé par `AdminDashboardServiceTest::test_url_filter_is_disabled_when_coordinator_has_no_tenant_url`)
— sans ce segment, deux coordinateurs de la même institution mais de
`tenant_url` différents partageraient à tort le même résultat pendant 300s.

**REQ-3 — Comptage tentatives quiz batché**
THE SYSTEM SHALL calculer `user_attempts_count`, `user_can_attempt` et
`user_best_attempt` pour TOUS les quiz de la page paginée en **une** requête
(`QuizAttempt::whereIn('quiz_id', $ids)->where('user_id', ...)`), au lieu d'un
enchaînement de requêtes par quiz.

**REQ-4 — Statistiques matières batchées**
THE SYSTEM SHALL calculer les compteurs `nombre_lessons_publiees`,
`nombre_lessons_brouillons` et `nombre_seances` de **toutes** les matières
retournées par KLASSCI en 3 requêtes groupées (`whereIn` + `groupBy`), au lieu
de 3 requêtes par matière.

**REQ-5 — Comportement fonctionnel inchangé**
THE SYSTEM SHALL produire des payloads strictement identiques à la version non
batchée (mêmes valeurs, même tri, mêmes clés) pour les 3 sites.

**REQ-6 — Comptes de requêtes constants**
THE SYSTEM SHALL rendre le nombre de requêtes des sites 2 et 3 **indépendant**
du nombre de quiz / matières traités (borné, hors requêtes de setup).

## Critères d'acceptation

1. Test cache : deux appels successifs à `buildStats` pour la même institution
   dans la fenêtre TTL → 1 seule exécution de l'agrégation (assertion sur le
   nombre de requêtes ou un compteur d'appel).
2. Test isolation : deux institutions distinctes → deux clés de cache
   distinctes, aucune fuite de compteur (pattern `AdminAnalyticsCacheIsolationTest`).
3. Test isolation intra-institution : deux coordinateurs de la même institution
   avec des `klassci_tenant_url` différents → résultats non partagés pendant le
   TTL.
4. Test anti-N+1 site 2 : nombre de requêtes de `GET /api/quizzes` constant
   quand le nombre de quiz sur la page croît (pattern baseline vs afterGrowth).
5. Test anti-N+1 site 3 : nombre de requêtes de `myMatieres()` constant quand
   le nombre de matières KLASSCI croît.
6. Non-régression : `AdminDashboardServiceTest`, `QuizCrudResponseTest` et toute
   suite Matiere/Quiz existante passent après batching (constructeur
   `AdminDashboardService` change de signature → sweep `new AdminDashboardService(`).
7. `php artisan test` 100 %, PHPStan level 9 = 0 nouvelle erreur, garde tailles
   (services ≤300 l) respectée.

## Q15 — Critères d'invalidation

- ❌ Cache qui fuit entre institutions (régression du bug historique #23).
- ❌ Cache qui fuit entre coordinateurs de tenant_url différents dans la même
  institution (nouvelle classe de bug introduite par le cache lui-même).
- ❌ `bestAttemptForUser` batché qui ne respecte plus l'ordre `score DESC` exact
  de la requête SQL d'origine (tri en mémoire divergent du tri SQL, notamment
  sur les valeurs NULL).
- ❌ `canUserAttempt` batché qui diverge de `isAvailable(quiz) && count < max_attempts`.
- ❌ Régression du filtre `published()` (status + published_at NOT NULL + passé)
  lors du passage en requête groupée pour les matières.
