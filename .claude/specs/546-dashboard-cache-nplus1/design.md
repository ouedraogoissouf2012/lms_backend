# Design — N+1 DB + dashboard admin non caché (#546)

## Site 1 — `AdminDashboardService`

Aligné sur le pattern déjà éprouvé de `SystemMetricsService`/`ActivityTrendsService`
(`app/Services/AdminAnalytics/*`), **volontairement pas** sur `TenantScopedCache`
(`app/Services/Cache/TenantScopedCache.php`) : son `remember()` tombe en no-op
de scoping sur le store `database` (pas de support des tags → clé NON préfixée
tenant en fallback) — bug documenté et corrigé séparément par #547. Réutiliser
ce collaborateur ici aurait importé le bug dans un 3ᵉ service avant son fix.

Le pattern retenu scope l'isolation **dans la clé elle-même** (pas via tags),
donc sûr sur tous les drivers de cache (`database`, `redis`, `array`) :

```php
final class AdminDashboardService
{
    private const RECENT_DAYS = 7;
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheRepository $cache,       // Illuminate\Contracts\Cache\Repository
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function buildStats(User $user): array
    {
        return $this->cache->remember(
            $this->cacheKey($user),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->aggregate($user),
        );
    }

    private function aggregate(User $user): array { /* corps actuel de buildStats(), verbatim */ }

    private function cacheKey(User $user): string
    {
        $institution = $this->tenantManager->getResolvedSlug();
        $scope = $user->klassci_tenant_url !== null ? md5($user->klassci_tenant_url) : 'all';

        return "admin_dashboard_stats_{$institution}_{$scope}";
    }
}
```

`$scope` : `md5()` n'est PAS utilisé ici comme primitive d'isolation
cross-tenant (rôle tenu par `$institution`, obligatoire et toujours en tête de
clé) — il ne fait que départager, **à l'intérieur** d'une même institution déjà
isolée, deux coordinateurs de `klassci_tenant_url` différents. Une collision
`md5` résiduelle resterait donc confinée à la même institution, jamais
cross-tenant.

**Limite héritée assumée (hors scope, périmètre fichiers #546 exclut
`TenantManager`/`EnsureRole`)** : `getResolvedSlug()` est fail-secure (throw
`RuntimeException` si le tenant n'est pas résolu). Un *platform supradmin*
(`institution_id` null) qui appellerait `/dashboard/stats` — via le bypass
universel `EnsureRole::isPlatformSupradmin()` — déclencherait cette exception
**après** le fix, alors qu'aujourd'hui `buildStats()` réussit (stats non
scopées, cross-institution, effet de bord non documenté plutôt qu'un choix
produit). C'est EXACTEMENT le comportement déjà présent sur
`/admin/analytics/system-metrics` et `/activity-trends` (même middleware
`role:coordinateur,superAdmin`, même bypass, même `getResolvedSlug()`) — donc
une limite **préexistante et cohérente à l'échelle du projet**, pas une
régression introduite ici. Non traitée dans ce ticket (hors domaine de
fichiers ; supradmin n'est pas le consommateur visé de ce dashboard
institution-scoped).

## Site 2 — `QuizCrudService::list`

Nouvelle méthode batchée sur `QuizAccessService` (déjà propriétaire de la
logique d'accès/tentatives — SRP respecté, pas de nouvelle classe) :

```php
/**
 * Tentatives finalisées (submitted|graded) de $userId pour un ensemble de
 * quiz, groupées par quiz_id et triées score DESC — 1 requête au lieu de N.
 * L'ordre global `orderBy('score','desc')` avant `groupBy` préserve, par
 * groupe, EXACTEMENT l'ordre que produirait la requête par-quiz d'origine
 * (même comportement NULL SQL, pas de tri PHP divergent).
 *
 * @param  array<int, int>  $quizIds
 * @return Collection<int, Collection<int, QuizAttempt>>
 */
public function finalizedAttemptsByQuiz(array $quizIds, int $userId): Collection
{
    if ($quizIds === []) {
        return collect();
    }

    return QuizAttempt::whereIn('quiz_id', $quizIds)
        ->where('user_id', $userId)
        ->whereIn('status', ['submitted', 'graded'])
        ->orderBy('score', 'desc')
        ->get()
        ->groupBy('quiz_id');
}
```

`QuizCrudService::list` :

```php
$quizIds = $quizzes->getCollection()->pluck('id')->all();
$attemptsByQuiz = $this->access->finalizedAttemptsByQuiz($quizIds, $user->id);

$quizzes->getCollection()->transform(function (Quiz $quiz) use ($attemptsByQuiz): Quiz {
    $attempts = $attemptsByQuiz->get($quiz->id, collect());

    $quiz->user_attempts_count = $attempts->count();
    $quiz->user_can_attempt    = $this->access->isAvailable($quiz) && $attempts->count() < $quiz->max_attempts;
    $quiz->user_best_attempt   = $attempts->first();

    return $quiz;
});
```

Équivalence stricte avec l'existant :
- `user_attempts_count` = `attemptsCountForUser` (même filtre statut, même count).
- `user_can_attempt` = `canUserAttempt` = `isAvailable($quiz) && attemptsCountForUser(...) < max_attempts`
  (`isAvailable` reste appelé par quiz — 0 requête, lit les attributs déjà chargés).
- `user_best_attempt` = `bestAttemptForUser` (même filtre, même tri `score DESC`).
- `user_latest_attempt` (utilisé par `show()`, PAS par `list()`) : non touché,
  `latestAttemptForUser` reste appelé tel quel (single-quiz, pas de N+1 sur `show`).

Résultat : 1 requête pour toute la page (au lieu de jusqu'à 3×N).

## Site 3 — `MyMatieresQueryService`

Deux requêtes agrégées `groupBy('matiere_id')` (Lesson) + une pour Seance, au
lieu de 3×N. `id`/`matiere_id` viennent du payload KLASSCI (`mixed`) : narrowing
via `KlassciPayload::toInt` (pattern déjà utilisé par
`TeacherEvaluationResultsService`, `App\Services\Seances\KlassciPayload`).

```php
public function getMatieresForUser(User $user): array
{
    // ... fetch KLASSCI payload inchangé ...

    $matiereIds = array_values(array_filter(array_map(
        fn (array $m): ?int => KlassciPayload::toInt($m['id'] ?? $m['matiere_id'] ?? null),
        $matieres,
    )));

    $stats = $this->preloadStats($matiereIds);

    $matieresEnrichies = array_map(
        fn (array $matiere): array => $this->enrichMatiere($matiere, $evaluations, $stats),
        $matieres,
    );

    // ...
}

/**
 * @param  array<int, int>  $matiereIds
 * @return array{published: Collection<int,int>, draft: Collection<int,int>, seances: Collection<int,int>}
 */
private function preloadStats(array $matiereIds): array
{
    if ($matiereIds === []) {
        return ['published' => collect(), 'draft' => collect(), 'seances' => collect()];
    }

    $published = Lesson::whereIn('matiere_id', $matiereIds)
        ->published()
        ->selectRaw('matiere_id, COUNT(*) as cnt')
        ->groupBy('matiere_id')
        ->pluck('cnt', 'matiere_id');

    $draft = Lesson::whereIn('matiere_id', $matiereIds)
        ->where('status', 'draft')
        ->selectRaw('matiere_id, COUNT(*) as cnt')
        ->groupBy('matiere_id')
        ->pluck('cnt', 'matiere_id');

    $seances = Seance::whereIn('klassci_matiere_id', $matiereIds)
        ->selectRaw('klassci_matiere_id, COUNT(*) as cnt')
        ->groupBy('klassci_matiere_id')
        ->pluck('cnt', 'klassci_matiere_id');

    return compact('published', 'draft', 'seances');
}

private function enrichMatiere(array $matiere, array $evaluations, array $stats): array
{
    $matiereId = KlassciPayload::toInt($matiere['id'] ?? $matiere['matiere_id'] ?? null);

    if ($matiereId === null) {
        return $matiere; // comportement préservé : id absent/non numérique → pas d'enrichissement
    }

    $matiere['statistiques'] = [
        'nombre_lessons_publiees'   => (int) ($stats['published'][$matiereId] ?? 0),
        'nombre_lessons_brouillons' => (int) ($stats['draft'][$matiereId] ?? 0),
        'nombre_seances'            => (int) ($stats['seances'][$matiereId] ?? 0),
        'nombre_evaluations'        => /* inchangé, calcul en mémoire */,
    ];

    return $matiere;
}
```

`published()` (scope Lesson : `status='published' AND published_at NOT NULL AND
published_at <= now()`) reste appliqué tel quel — seule la boucle par-matière
disparaît, pas la condition métier.

`$matiereId === null` change de comportement mineur assumé : le code actuel
utilise `$matiere['id'] ?? $matiere['matiere_id']` **sans narrowing** (un id
KLASSCI non numérique planterait silencieusement en SQL implicite `WHERE
matiere_id = 'abc'` → 0 résultats de toute façon sur une colonne entière).
`KlassciPayload::toInt` rend ce cas explicite (retour early, mêmes 0 counts)
sans changer le résultat observable.

## Non-buts

- Pas d'invalidation active du cache dashboard admin (TTL 300s suffit, cohérent
  avec les 2 services sœurs).
- Pas de changement du format de réponse des 3 endpoints.
- Pas de correctif sur `TenantScopedCache` (périmètre #547).
