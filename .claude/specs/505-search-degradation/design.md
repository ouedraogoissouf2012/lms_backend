# Design — #505 Drapeau de dégradation par source

## 1. Ce que la correction change vraiment

Aujourd'hui, une panne KLASSCI est **indiscernable d'un résultat vide** : les deux
se traduisent par `categories.classes = 0`. Le correctif consiste à faire remonter
l'information *jusqu'au client*, et à **cesser de la figer 5 minutes dans le cache**.

```mermaid
flowchart TD
    A[search] --> B{cache hit ?}
    B -- oui --> C[résultat complet, sources_failed = []]
    B -- non --> D[aggregate]
    D --> E[3 sources locales]
    D --> F[2 sources KLASSCI]
    F -- panne --> G[bucket vide + source nommée]
    D --> H{complet ?}
    H -- oui --> I[mise en cache 300 s]
    H -- non --> J[AUCUNE mise en cache]
    I --> K[payload + sources_failed]
    J --> K
```

## 2. Décisions

### 2.1 `sources_failed` toujours présent, éventuellement vide

Un client ne doit pas avoir à distinguer « clé absente » de « aucune panne ». La clé
est donc **systématiquement** présente. C'est une extension **additive** du contrat :
`success`, `query`, `results`, `total`, `categories` sont inchangés.

Un test de caractérisation existant (`SearchResponseTest::test_global_search_keeps_root_keys`)
verrouille la liste EXACTE des clés racine ; il est mis à jour **délibérément**, avec
le commentaire expliquant l'extension — pas ajusté en silence.

### 2.2 Un résultat dégradé est mis en cache TRÈS brièvement, avec son drapeau

`cache->remember()` ne permet pas de décider APRÈS coup de la durée de
mémorisation. Le flux devient donc explicite : `get` → calcul → `put` dont le TTL
dépend de l'état de santé (300 s si complet, **30 s** si dégradé). L'entrée
dégradée **embarque `sources_failed`** : elle n'est donc jamais servie comme si
elle était complète, ce que faisait le cache de 5 minutes d'origine.

**Correction apportée après revue.** La première version ne mettait RIEN en cache
en cas de dégradation, au motif que « l'appel KLASSCI est déjà protégé en aval par
son cache tenant de 600 s ». C'était faux sur le chemin d'échec :
`TenantScopedCache::remember()` ne mémorise pas une exception, donc ce TTL ne
protège rien tant que KLASSCI est en panne. Seul `KlassciCircuitBreaker` borne le
trafic sortant — et **rien** ne bornait les trois `LIKE '%…%'` locaux (dont un sur
`lessons.content`, non indexable) rejoués à chaque frappe d'un endpoint de saisie
assistée. Le TTL court, déjà autorisé par l'issue, rétablit cette borne sans
jamais présenter un résultat amputé comme complet.

La clé de cache est désormais **versionnée** (`global_search_v2_`) et sa forme
**validée** à la relecture : la forme mémorisée a changé, et un bucket ajouté ou
renommé par un futur déploiement ne doit pas faire échouer les comptages sur les
entrées encore chaudes.

### 2.2 bis L'enveloppe KLASSCI est déballée

Découvert en revue, et **corrigé ici** plutôt que différé : `searchClasses` /
`searchMatieres` parcouraient la réponse BRUTE du proxy, alors que KLASSCI
enveloppe ses collections. Quatre autres appelants des mêmes méthodes lisent
`['data']` — `EvaluationCreationService:161,173`, `EvaluationEnrichmentService:64`,
`StudentGradesAggregator:59`. Ces deux sources ne trouvaient donc jamais rien.

Le laisser en l'état aurait été pire que le défaut d'origine : le drapeau
introduit par cette issue serait allumé **en permanence** sur un KLASSCI
parfaitement sain. La lecture accepte les deux formes (enveloppe et liste nue) et
refuse explicitement toute autre — un refus devient une source dégradée nommée,
pas un « 0 résultat » silencieux.

### 2.3 Où se pose le `try`

Le `catch` descend d'un cran : il n'entoure plus le seul appel réseau mais **toute
la production de la source**. Deux effets :

1. la source est nommée dans `sources_failed` au lieu d'être confondue avec 0 résultat ;
2. un payload KLASSCI **mal formé** (aujourd'hui hors du `try` : `collect($all)->filter(fn (array $c) ...)`
   lève un `TypeError` non intercepté, donc un **500**) devient une dégradation
   propre de cette seule source.

Le message d'erreur reste **serveur uniquement** (`logger->error`) : le client ne
reçoit que le nom de la source (§1.2 — ne jamais exposer le détail technique).

## 3. Découpage (contrainte §1.1)

`GlobalSearchService` fait **285 lignes** ; le garde-fou `scripts/check-file-sizes.php`
refuse au-delà de 300. Ajouter la mécanique de dégradation ferait dépasser la limite :
le découpage n'est donc pas cosmétique, il est imposé.

| Classe | Responsabilité | Lignes visées |
|---|---|---|
| `GlobalSearchService` | orchestration : cache conditionnel, agrégation, sources **locales** | ~230 |
| `KlassciSearchSources` (nouveau) | recherche `classes` + `matieres` chez KLASSCI ; **laisse remonter** les pannes | ~110 |
| `SearchAggregate` (nouveau, VO) | résultats + sources en échec ; `isComplete()` | ~45 |

`KlassciSearchSources` est injecté dans `GlobalSearchService` (§1.6 — D), ce qui rend
la panne KLASSCI **simulable en test unitaire** sans toucher au réseau ni au proxy.

## 4. Stratégie de test

| Cas | Attendu |
|---|---|
| KLASSCI répond | `sources_failed = []`, buckets remplis, résultat mis en cache |
| `getClasses` lève | `sources_failed = ['classes']`, buckets locaux intacts, `matieres` intact |
| `getMatieres` lève | `sources_failed = ['matieres']` |
| les deux lèvent | `sources_failed = ['classes','matieres']` |
| résultat dégradé | **absent du cache** ; l'appel suivant réinterroge et renvoie complet |
| résultat complet | présent en cache ; l'appel suivant ne réinterroge pas KLASSCI |
| payload KLASSCI mal formé | dégradation de la source, pas 500 |
| étudiant (non-staff) | `sources_failed = []` — les sources KLASSCI ne sont pas sollicitées |
| contrat HTTP | `GET /api/search` expose `sources_failed` |
