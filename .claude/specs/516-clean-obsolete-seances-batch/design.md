# Design — #516 [PERF][HIGH] CleanObsoleteSeances : requête non bornée + N+1 HTTP

> **Révisé après implémentation + `/code-review effort max` + audits
> `spec-security`/`spec-architect`.** Le design initial ci-dessous a évolué
> sur deux points significatifs pendant la revue : (1) `SeanceExistenceBatchChecker`
> ne dépend plus de `TenantManager`/`KlassciConfigResolver` du tout — `baseUrl`/
> `token` lui sont passés en paramètres explicites par le Job (2 itérations
> intermédiaires ont été nécessaires : d'abord `KlassciConfigResolver` injecté
> directement — bug de mémorisation cross-tenant trouvé par 3 agents de revue
> indépendants — puis `Container::make()` frais à chaque appel — service
> locator anti-pattern relevé par l'audit `spec-architect` — avant d'arriver à
> la version finale, sans aucun état ambiant) ; (2) l'archivage se fait en 1
> seul `UPDATE` par lot (`whereIn(...)->update(...)`), pas un `save()` par
> séance (N+1 SQL en écriture relevé par le même audit). Cette version reflète
> le code RÉELLEMENT livré.

## 1. Nouveau collaborateur : `SeanceExistenceBatchChecker`

`app/Services/Seances/Sync/SeanceExistenceBatchChecker.php` — pool HTTP direct
(comme `KlassciBatchFetcher::buildPoolRequests`, même pattern), retourne un
**statut par ID à 3 valeurs** au lieu d'un map "omission silencieuse", et ne
lit **aucun état ambiant** — `baseUrl`/`token` sont des paramètres :

```php
enum SeanceCheckResult: string
{
    case Exists = 'exists';
    case ConfirmedDeleted = 'confirmed_deleted'; // 404
    case Error = 'error';                        // timeout, 5xx, connexion, URL invalide
}

final class SeanceExistenceBatchChecker
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
        // poolSize/connectTimeout/timeout/sslVerify lus depuis config() —
        // factorisés via positiveIntConfig() (évite 3× le même ternaire).
    }

    /**
     * @param  array<int>  $klassciSeanceIds
     * @param  string  $baseUrl  URL de base KLASSCI DE L'INSTITUTION appelante.
     * @param  ?string  $token   Token système de CETTE MÊME institution.
     * @return array<int, SeanceCheckResult>
     */
    public function checkMany(array $klassciSeanceIds, string $baseUrl, ?string $token): array
    {
        if ($klassciSeanceIds === []) {
            return [];
        }

        $results = [];
        foreach (array_chunk(array_values(array_unique($klassciSeanceIds)), $this->poolSize) as $batch) {
            $responses = $this->http->pool(fn ($pool) => $this->buildPoolRequests($pool, $batch, $baseUrl, $token));

            foreach ($batch as $id) {
                $results[$id] = $this->classify($responses[(string) $id] ?? null);
            }
        }

        return $results;
    }

    private function classify(mixed $response): SeanceCheckResult
    {
        if (!$response instanceof Response) {
            return SeanceCheckResult::Error; // exception de transport
        }
        if ($response->ok()) {
            return SeanceCheckResult::Exists;
        }
        if ($response->status() === 404) {
            return SeanceCheckResult::ConfirmedDeleted;
        }

        return SeanceCheckResult::Error;
    }
}
```

**Pourquoi `baseUrl`/`token` en paramètre, pas résolus en interne** (révisé
après audit architecture) : `KlassciConfigResolver` est un résolveur 3-tiers
conçu pour un contexte HTTP-utilisateur (token personnel Sanctum en priorité
1/2) — ces deux priorités sont TOUJOURS mortes dans un contexte de Job de
queue (aucun guard Sanctum authentifié). Seule la priorité 3
(`Institution::getKlassciConfig()`, qui retourne littéralement
`$institution->klassci_api_url`/`klassci_api_token`) s'applique jamais ici.
`CleanObsoleteSeances` a DÉJÀ l'`Institution` en main à l'appel de
`checkMany()` — lui faire porter ces deux valeurs en paramètre élimine
d'un coup : la dépendance à `Container`/`KlassciConfigResolver`, le risque de
staleness (plus aucun état interne à figer entre deux appels sur la même
instance de checker, ce qui a réellement causé un bug pendant l'implémentation
— voir historique dans le docblock de classe), et un service-locator caché
dans le corps de `checkMany()` qui rendait la classe non mockable par simple
injection constructeur en test.

**Pourquoi un enum plutôt qu'une string** (cohérence Q6) : évite toute
comparaison par chaîne fragile (`=== '404'` vs `str_contains(...)`, la source
même du bug initial — un message d'exception qui CONTIENT accidentellement
"404" ailleurs dans son texte donnait un faux positif). `SeanceCheckResult`
type le contrat, PHPStan vérifie l'exhaustivité des `match()`.

**Pas de circuit breaker ni de cache ici** (limite honnête, Q15) : ni
`KlassciBatchFetcher::fetchManyByEndpoint()` ni cette nouvelle classe ne
consultent `KlassciCircuitBreaker` (vérifié : absent des deux). C'est un défaut
pré-existant de l'infrastructure batch partagée, pas introduit ici — hors
périmètre du fichier assigné (`KlassciCircuitBreaker` vit dans
`app/Services/Klassci/*`). Pas de cache non plus (délibéré, contrairement à
`KlassciBatchFetcher`) : une vérification d'existence dont le seul but est de
détecter une suppression ne doit jamais servir une réponse mise en cache.

## 2. Refactor de `CleanObsoleteSeances`

```php
public function handle(SeanceExistenceBatchChecker $checker, TenantManager $tenantManager, LoggerInterface $logger): void
{
    $logger->info('🧹 [CleanObsoleteSeances] Début du nettoyage des séances obsolètes');

    // reset() EN DÉBUT (pattern GenerateReportPdf #536) : purge un tenant
    // résiduel AVANT la requête cross-tenant ci-dessous.
    $tenantManager->reset();

    $startedAt = microtime(true);
    $stats = ['checked' => 0, 'archived' => 0, 'errors' => 0, 'institutions_skipped' => 0, 'budget_atteint' => false];

    $institutionIds = Seance::where('is_active', true)
        ->whereNotNull('klassci_seance_id')->whereNotNull('institution_id')
        ->distinct()->pluck('institution_id');

    // Préchargement en 1 requête (pas de N+1 SQL en lecture).
    $institutions = Institution::whereIn('id', $institutionIds)->get()->keyBy('id');

    try {
        foreach ($institutionIds as $institutionId) {
            $budgetReached = $this->processInstitution($institutionId, $institutions, $checker, $tenantManager, $logger, $stats, $startedAt);
            if ($budgetReached) {
                $stats['budget_atteint'] = true;
                break;
            }
        }
    } finally {
        $tenantManager->reset(); // #539/CRITICAL-07, garanti même sur exception.
    }

    $logger->info('✅ [CleanObsoleteSeances] Nettoyage terminé', $stats);
}
```

`processInstitution()` (méthode privée) : résout l'institution, skip proprement
si `klassci_api_url`/`klassci_api_token` absents (R2), **`try/catch(\Throwable)`
autour de `cleanInstitution()`** — isolation de panne PAR INSTITUTION, parité
avec l'ancien comportement par-séance (une institution en échec, ex. URL
malformée, token corrompu par une rotation `APP_KEY`, n'interrompt jamais les
autres).

`cleanInstitution()` : `$tenantManager->set($institution)` (borne le scope
global `BelongsToInstitution` sur la requête `Seance` de cette institution —
SEUL rôle restant de `TenantManager` ici, la résolution KLASSCI ne l'utilise
plus), `chunkById` + budget-temps préservés, `$checker->checkMany($ids,
$institution->klassci_api_url, $institution->klassci_api_token)` par lot.

`checkAndArchiveBatch()` : classifie chaque résultat, collecte les IDs
`ConfirmedDeleted` (R4), puis archive en **1 seul** `Seance::whereIn('id',
$ids)->update(['is_active' => false])` — jamais un `save()` par séance.

## 3. Alternatives écartées (Q12 self-critique)

1. **Réutiliser `KlassciBatchFetcher::fetchManyByEndpoint()`** — écarté, cf.
   requirements.md (omission silencieuse = ne distingue pas 404 confirmé
   d'erreur transitoire → archivage à tort sur toute panne réseau).
2. **`KlassciConfigResolver` injecté directement dans `SeanceExistenceBatchChecker`**
   — 1ère itération, ABANDONNÉE : mémorisation par instance (« singleton
   implicite par requête HTTP ») + réutilisation du même checker sur plusieurs
   institutions dans la boucle `handle()` = staleness cross-tenant après la
   1ère institution. Trouvé indépendamment par 3 agents de revue de code.
3. **`Container::make(KlassciConfigResolver::class)` frais à chaque appel**
   — 2ème itération, ABANDONNÉE : corrige la staleness mais introduit un
   service locator (dépendance cachée dans le corps de méthode, classe non
   mockable par simple injection constructeur — relevé par l'audit
   `spec-architect`). Remplacée par la solution finale (§1) : paramètres
   explicites, aucune dépendance à `Container` ni `KlassciConfigResolver`.
4. **Corriger `KlassciConfigResolver` pour accepter un institution_id explicite**
   — écarté : fichier hors périmètre assigné à cette issue.
5. **Garder un seul admin arbitraire mais boucler dessus par institution** —
   écarté : `Institution::klassci_api_token` est un token SYSTÈME déjà propre
   à l'institution, plus robuste qu'un lookup d'admin qui peut ne pas exister.

## 4. Tests

1. RED puis GREEN : isolation cross-tenant (2 institutions, `Http::preventStrayRequests()`
   + `Http::assertSent()` sur l'hôte réel — pas seulement l'état `is_active` en
   sortie, qui peut passer pour la mauvaise raison avec `Http::fake()` seul).
2. Anti-N+1 HTTP : nombre d'appels `HttpFactory::pool()` croît par lots de
   taille fixe (pattern baseline-vs-afterGrowth), jamais linéairement.
3. `SeanceCheckResult::ConfirmedDeleted` (404) → archivé ;
   `SeanceCheckResult::Error` (timeout/5xx/URL invalide) → PAS archivé.
4. Institution sans config KLASSCI exploitable → skip propre, les autres
   institutions sont quand même traitées.
5. Institution avec URL KLASSCI malformée (truthy mais invalide) → isolée
   sans bloquer les autres (isolation de panne par institution).
6. Budget-temps PAR institution : institution non atteinte → reportée,
   comportement idempotent (repris de `DrainBudgetTest`).
7. Anti-N+1 SQL en écriture : archivage de 10 séances confirmées supprimées
   dans le même lot → 1 seul `UPDATE`, pas 10 (`DB::enableQueryLog()`).
