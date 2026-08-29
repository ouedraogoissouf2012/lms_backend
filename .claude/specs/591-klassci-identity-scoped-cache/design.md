# Design — #591 Partitionnement du cache KLASSCI par porteur

## 1. Solution retenue (une seule, §6)

Faire du **porteur un paramètre obligatoire** des deux raccourcis liés à
l'identité, et le lire sur l'objet `Request` de la requête courante.

```
ProxyAcademicController::evaluations(Request $request)
   │
   ├─ personalKlassciToken($request)  ──▶ null ──▶ 401 fail-secure (R3/R4)
   │        │ $request->user()->klassci_token      (source per-requête, R7)
   │        ▼
   └─ KlassciProxyService::getEvaluations($userToken, $filters)
            │
            └─▶ requestWithUserToken($userToken, 'evaluations', 'GET', $filters, 300)
                   │
                   ├─ memoKey('GET', endpoint, params, xxh3($userToken))
                   └─ generateUserTokenKey(endpoint, params, xxh3($userToken))
                          → klassci_{tenant}_{tokenHash}_evaluations_{md5(params)}_{ts}
```

`xxh3(tokenA) ≠ xxh3(tokenB)` → clés disjointes → isolation **par
construction**, sur les deux couches (memo intra-requête ET cache distribué).

### 1.1 Pourquoi la signature change (et pourquoi c'est le cœur du correctif)

```php
- public function getEvaluations(array $filters = []): array
+ public function getEvaluations(string $userToken, array $filters = []): array
```

Le défaut n'est pas « on met en cache », c'est « on met en cache une réponse liée
au porteur sous une clé qui ignore le porteur ». On aurait pu se contenter de
changer le corps de la méthode ; rendre le jeton **obligatoire** fait mieux :
l'appel sans porteur devient **impossible à écrire** (R6). Un futur appelant ne
peut plus rouvrir la faille par inadvertance — le typage le lui interdit, PHPStan
le signale, la revue n'a plus à y penser. Le blast radius est minuscule (2
appelants dans tout le dépôt, tous deux dans `ProxyAcademicController`).

### 1.2 Fail-secure plutôt que repli (R3/R4)

Un compte sans jeton KLASSCI personnel n'a pas d'identité KLASSCI. Trois options
existaient ; deux sont dangereuses :

| Option | Effet pour un compte sans jeton perso |
|---|---|
| Repli sur le jeton d'institution/système | lui sert la vue **tenant-wide** du jeton système — plus de données que ce à quoi il a droit |
| Statu quo (clé globale) | lui sert la charge utile **d'un autre utilisateur** — la fuite d'aujourd'hui |
| **401 fail-secure** (retenue) | refus explicite, aucune donnée d'autrui |

Le 401 n'enlève donc pas une fonctionnalité saine : il remplace une fuite. Et il
reprend mot pour mot le contrat déjà en vigueur sur les endpoints personnels du
même préfixe (`ProxyDashboardController`, `401 « Token KLASSCI non trouvé.
Veuillez vous reconnecter. »`).

---

## 2. Composants

| # | Fichier | Nature | Rôle |
|---|---|---|---|
| 1 | `app/Services/Klassci/Concerns/HasKlassciEndpointShortcuts.php` | signatures + contrat + doc | `getEvaluations`/`getEmploiTemps` exigent le porteur et passent par `requestWithUserToken` ; classification des endpoints documentée dans le docblock du trait |
| 2 | `app/Http/Controllers/API/Proxy/ProxyAcademicController.php` | 2 méthodes + 2 helpers privés | lit le porteur sur la `Request`, refuse en 401 sinon |
| 3 | `tests/Feature/Proxy/ProxyAcademicPerUserIsolationTest.php` | **nouveau** | preuve RED→GREEN (5 cas) |
| 4 | `tests/Unit/Services/Klassci/KlassciEndpointClassificationGuardTest.php` | **nouveau** | rend la règle de classification **exécutable** (§4.1) |

Aucun changement de constructeur, aucune nouvelle dépendance DI, aucun binding :
blast radius nul côté conteneur.

---

## 3. Découverte en cours de route — mémoïsation du contrôleur (risque Octane)

Une **première** version de ce correctif dérivait le porteur d'un collaborateur
injecté au constructeur (`KlassciConfigResolver`, lié en `scoped`). Le test est
resté **rouge** malgré une implémentation correcte. L'instrumentation a montré
pourquoi :

```
[PROXY] getEvaluations on proxy=3071   ← requête d'Alice
[PROXY] getEvaluations on proxy=3071   ← requête de Bob : MÊME instance
[RESOLVER] obj=1867 CACHED token='token-alice'
```

Laravel **mémoïse l'instance de contrôleur sur l'objet `Route`**
(`Route::$controller`), et l'objet `Route` survit à la requête dans le
`RouteCollection`. Le seul flush est `Route::flushController()` — **appelé par
aucun code du framework** (vérifié : unique occurrence dans
`Illuminate/Routing/Route.php:327`), il existe pour Octane. Conséquence : toute
dépendance résolue au constructeur d'un contrôleur peut survivre à la requête.

Deux enseignements :

1. **Pour ce correctif** : le porteur ne doit pas venir du conteneur mais de
   l'objet `Request` passé en argument de méthode — per-requête par construction
   (R7). C'est la conception retenue.
2. **Risque pré-existant, hors périmètre** : sous PHP-FPM (un process = une
   requête) le problème est invisible. Sous **Octane** (épique scalabilité #378),
   si le flush n'était pas effectué, `ProxyAcademicController` et
   `ProxyDashboardController` — qui injectent `KlassciProxyService` →
   `KlassciHttpClient` → `KlassciConfigResolver` mémoïsé — enverraient le jeton
   du **premier** appelant du worker pour toutes les requêtes suivantes. À
   vérifier explicitement avant la bascule Octane. → **signalé, non corrigé ici**.

   Relevé par l'audit `spec-architect` : cela vaut aussi pour les **écritures**
   du fichier corrigé — `saveNotes()`, `savePresences()` et `updateCoursStatut()`
   continuent de propager le porteur *implicitement* via `KlassciConfigResolver`.
   Ce diff fait donc coexister deux conventions dans un même fichier : argument
   explicite pour les lectures corrigées, résolution par conteneur pour les
   écritures. Sans cache, aucune fuite aujourd'hui ; sous Octane sans flush, ce
   serait une écriture de notes avec le jeton du **mauvais enseignant**. À
   rattacher à la même issue de suivi Octane.

Le harnais de test intègre ce constat : `simulateFreshRequestBoundary()` purge
les instances `scoped` **et** les contrôleurs mémoïsés, faute de quoi il ne
simulerait ni PHP-FPM ni Octane.

---

## 4. Stratégie de test (R8)

`tests/Feature/Proxy/ProxyAcademicPerUserIsolationTest.php`, dans la lignée de
`AuthMePerUserIsolationTest` (#568) :

- On feinte **uniquement la frontière réseau** — la
  `Illuminate\Http\Client\Factory` injectée dans le vrai `KlassciHttpClient`
  (couture DIP). Tout le reste est réel : proxy, stratégie de clés, cache
  distribué (store `database`), memo.
- Le faux transport **route la réponse d'après le `Authorization: Bearer` reçu**.
  Aucun faux vert possible : si la réponse d'Alice arrive à Bob, c'est
  nécessairement le cache qui la lui a servie sans repasser par le réseau.
- Le compteur d'allers réseau par ressource permet d'asserter aussi bien
  l'absence d'appel (fail-secure) que le partage légitime (catalogue).

| Cas | Exigence | Assertion | Avant correctif |
|---|---|---|---|
| 2 enseignants, `/proxy/evaluations` | R1 | chacun reçoit SA charge utile | **RED** (Bob recevait 11, celle d'Alice) |
| 2 enseignants, `/proxy/emploi-temps` | R2 | idem | **RED** (Bob recevait 33) |
| compte sans jeton perso après qu'Alice a peuplé le cache | R3/R4 | 401, aucune donnée d'Alice, **aucun** appel réseau supplémentaire | vert (n'existait pas) |
| 2 enseignants de **2 institutions distinctes** | §1.3 multi-tenant | chacun sa charge utile, 2 allers réseau | ajouté après audit (§6.3) |
| `/proxy/structure` par 2 porteurs | R5 | 1 seul aller réseau | vert (garde anti-sur-correction) |

Les cas verts sont des **gardes** : ils doivent le rester après correctif.

### 4.1 Garde structurel — la règle devient exécutable

`KlassciEndpointClassificationGuardTest` réfléchit sur le trait et vérifie, pour
chaque raccourci déclaré lié à l'identité, que (a) la signature exige
`string $userToken` en premier paramètre non optionnel, et (b) le corps délègue à
`requestWithUserToken()` et **pas** à `get()`.

Motivation (audit `spec-architect`) : la classification ne vivait que dans un
docblock, et un docblock ne s'exécute pas. La garantie R6 protégeait les deux
méthodes converties, pas la **règle**. Ce garde échoue désormais si quelqu'un
dégrade un endpoint déjà classé. Il ne peut pas deviner la sémantique d'un
endpoint distant : ajouter un nouveau raccourci lié à l'identité sans l'inscrire
dans la liste reste possible — limite assumée et documentée dans le test.

---

## 5. Alternatives écartées (Q12)

1. **Dériver le porteur d'un `KlassciCredentialResolver` injecté** (interface
   d'un membre, jumelle de `KlassciTargetResolver` #578) — implémentée, testée,
   puis **rejetée sur preuve** : §3 ci-dessus. Elle était plus élégante sur le
   papier (aucun appelant à modifier, résolution 3-tiers préservée) mais faisait
   dépendre une valeur de sécurité d'un cycle de vie d'instance géré par le
   framework. Une garantie d'isolation ne doit pas reposer sur « le conteneur
   reconstruira bien cet objet ».
2. **Rendre `get()` « intelligent » (clé dérivée du porteur pour *tous* les
   endpoints)** — rejetée, comme dans le design #568 : partitionne les 8
   raccourcis catalogue (structure, filières, niveaux…) dont la charge utile est
   prouvément identique pour tout le tenant, divisant leur taux de hit par le
   nombre d'utilisateurs actifs pour zéro gain de sécurité (violation R5) ; et
   rend la classification implicite au lieu de la déclarer (violation R6).
3. **Cesser de mettre `evaluations`/`emploi-temps` en cache (TTL 0)** —
   rejetée : sacrifie PERF-02 sur deux endpoints chauds alors que le défaut est
   la *clé*, pas le fait de cacher. Corrige le symptôme.

## 6. Audit des autres appels `get()` globaux (R9)

| Point d'appel | Porteur résolu | Portée de la réponse | Verdict |
|---|---|---|---|
| `getStructure`, `getFilieres`, `getNiveauxEtudes`, `getEnseignants`, `getEnseignantsEnrichis`, `getClasses` | 1/2/3 | catalogue organisationnel, identique pour tout le tenant | clé globale **correcte** — inchangés |
| `testConnection()` → `get('structure')` | idem | idem | **correcte** |
| `DispatchLessonPublishedNotifications.php:130` → `get("/matieres/{id}")` | job worker, aucun user Sanctum → priorité 3 (jeton système) | catalogue | **correcte** (déjà tranché par le design #568) |
| `getEvaluations`, `getEmploiTemps` | 1 (jeton perso) | **liée au porteur** | **FUITE — corrigée ici** |
| `getMatieres`, `getMatiereDetails` | 1 (jeton perso) | **suspectée liée au porteur** | ⚠️ **FUITE PROBABLE — non corrigée, voir §6.1** |
| `getClasseEtudiants($classeId)` | 1 (jeton perso) | roster d'une classe adressée par ID | ⚠️ **risque résiduel — voir §6.2** |

> **Correction de ce tableau après audit `spec-security`.** Une première version
> verdictait `getMatieres`/`getMatiereDetails` « clé globale correcte » en les
> rangeant dans le catalogue organisationnel — **sans leur appliquer le standard
> de preuve exigé pour `evaluations`** (§Q15 : chercher dans le dépôt comment la
> ressource est consommée ailleurs). L'audit a produit cette preuve, et elle
> pointe dans l'autre sens. Le verdict est corrigé ci-dessus, et les
> avertissements sont désormais portés **sur les méthodes elles-mêmes** — un
> mainteneur lit le trait, pas ce document.

### 6.1 `getMatieres` / `getMatiereDetails` — fuite probable, NON corrigée (dette tracée)

Preuve issue de l'audit `spec-security` : le dépôt consomme `matieres` avec le
jeton du porteur partout ailleurs.

| Preuve | Citation |
|---|---|
| `MatiereSyncService.php:46-55` | « matières **accessibles à l'utilisateur (via son token KLASSCI)** » → `requestWithUserToken(...)` |
| `FetchesSeanceDataFromKlassci.php:32-33` | « toutes les matières **de l'enseignant** » |
| `UpcomingSeancesFetcher.php:96` | matières **de l'utilisateur** |
| `KlassciBatchFetcher::fetchManyMatieresDetails()` | `string $userToken` **non-nullable** pour `matieres/{id}` — l'endpoint exact servi en clé globale par `getMatiereDetails()` |

Les routes `/api/proxy/matieres` et `/api/proxy/matieres/{id}`
(`routes/api/core.php:86-87`) sont dans le même groupe **sans restriction de
rôle** que celles corrigées ici. Le vecteur est donc identique.

**Pourquoi ce n'est pas corrigé dans cette PR** — ce n'est pas le même chantier :

| | `getEvaluations` / `getEmploiTemps` | `getMatieres` / `getMatiereDetails` |
|---|---|---|
| Appelants | 2, tous deux des contrôleurs disposant d'une `Request` | 5, dont **4 services sans contexte de requête** : `EvaluationCreationService:160`, `EvaluationEnrichmentService:60`, `StudentGradesAggregator:58`, `GlobalSearchService:262` |
| Coût du porteur obligatoire | 2 lignes d'appel | remonter le jeton à travers les signatures publiques de 4 services + leurs appelants + leurs tests |

Élargir un correctif de sécurité à ce chantier, c'est en diluer la revue et en
multiplier le rayon d'impact. → **issue de suivi dédiée, avec son propre TDD.**

### 6.2 `getClasseEtudiants` — risque résiduel signalé, NON corrigé ici (dette tracée)

`getClasseEtudiants()` (`HasKlassciEndpointShortcuts.php:64`) est exposé par
`GET /api/proxy/classes/{id}/etudiants` (`routes/api/core.php:83`), dans le même
groupe **sans restriction de rôle**. Le roster est mis en cache sous une clé
globale au tenant. Si KLASSCI est le **seul** garde d'autorisation sur cette
ressource (le LMS ne vérifie pas l'appartenance à la classe avant l'appel),
alors :

> un utilisateur A autorisé sur la classe 5 peuple le cache ; un utilisateur B
> **non** autorisé demande la classe 5 et obtient un *cache hit* — KLASSCI ne
> voit jamais son jeton, son 403 n'est jamais émis. Le cache devient un
> contournement d'autorisation.

Même famille que #591, mais sur une ressource adressée par ID et non
« personnelle » — hors du périmètre fixé pour cette PR. Un correctif à la légère
toucherait aussi `NotifyUpcomingEvaluations` (commande console, jeton système) et
`TeacherEvaluationResultsService`, qui méritent leur propre TDD.

→ **Action proposée** : issue de suivi dédiée. Non corrigé ici, et explicitement
signalé plutôt que tu (§Engagement — signaler honnêtement les compromis).

---

## 7. Protocole 5-questions

- **Q1 racine ?** Oui — la clé, sur les deux couches, avec l'isolation rendue
  non contournable par la signature. §1.1.
- **Q2 sources ?** [RFC 9111 §3.5](https://datatracker.ietf.org/doc/html/rfc9111#section-3.5)
  (cache partagé + `Authorization`) et [§4.1](https://datatracker.ietf.org/doc/html/rfc9111#section-4.1)
  (clés secondaires) ; précédent interne #568/PR #585 ; code du framework
  (`Illuminate/Routing/Route.php:327`) pour §3.
- **Q3 nouveau problème ?** Oui, un assumé et signalé : 401 pour les comptes sans
  jeton personnel (§2.1 des requirements). Alternative = continuer à leur servir
  les données d'autrui.
- **Q11 meilleure ou plus rapide ?** La plus rapide était de changer le corps des
  2 méthodes sans toucher aux signatures. Retenue : la signature qui rend la
  faute impossible, plus les 2 helpers de contrôleur.
- **Q13 à 10× ?** Le partitionnement multiplie les entrées de ces 2 endpoints par
  le nombre d'utilisateurs **simultanément actifs** (TTL 300 s / 600 s bornent
  les entrées vivantes, pas la population totale). À 20 000 utilisateurs, avec
  5 % d'actifs sur 10 min et ~5 combinaisons de filtres : ≈ 10 000 entrées
  vivantes. À 10× : ≈ 100 000, croissance **linéaire**, bornée par le TTL. C'est
  le régime déjà imposé par les ~50 appels `requestWithUserToken()` existants
  (`me/dashboard`, `matieres`, `classes`…) : on ajoute 2 endpoints à un motif
  dominant, pas une nouvelle classe de pression.
- **Q15 qu'est-ce qui m'invaliderait ?** Une documentation KLASSCI établissant
  que `evaluations`/`emploi-temps` renvoient une charge utile **identique quel
  que soit le porteur** — la clé globale serait alors sûre et le correctif ne
  coûterait que du taux de hit. Deux raisons de le faire quand même : (a) les 4
  points d'appel du dépôt (requirements §1.2) attestent du contraire ;
  (b) asymétrie des risques — clé par porteur sur une donnée tenant-wide coûte du
  hit-ratio, clé globale sur une donnée liée à l'identité **fuit**. Le correctif
  est correct sous les deux hypothèses ; le statu quo sous une seule.

## 8. Conformité

- §1.1 / §5 : `ProxyAcademicController` 189 lignes (≤200), trait 281 lignes,
  `KlassciProxyService` 271 lignes — aucun fichier `app/` > 300. Méthode la plus
  longue ajoutée : 11 lignes (≤40).
- §1.2 : aucun jeton en clair — la clé ne porte qu'un `xxh3` tronqué, jamais
  loggé ; aucun `getMessage()` exposé.
- §1.3 : TDD RED→GREEN, 2 institutions couvertes, 10/10 verts sur le périmètre.
- §1.6 : SRP (helpers à un verbe), DIP inchangé, pas de `new` ni de Facade
  ajoutés, aucun changement de constructeur.
- PHPStan : 0 erreur, baseline 336/443 inchangée.

## 9. Suites données aux audits `spec-security` / `spec-architect`

| Constat | Décision |
|---|---|
| **HIGH** `getMatieres`/`getMatiereDetails` : même classe de fuite, certifiés « corrects » par la 1ʳᵉ version de ce document | **Verdict corrigé** (§6 + §6.1), avertissements portés sur les méthodes. **Non corrigé** : 4 des 5 appelants sont des services sans `Request` → issue de suivi dédiée. |
| **MEDIUM** le docblock rangeait « classes » / « matières » en tenant-partagé alors que §6.1/§6.2 les signalent | **Corrigé** : la classification ne cite plus que les endpoints prouvés, et liste explicitement les non tranchés. |
| **MEDIUM** la classification n'est que de la prose, non exécutable | **Corrigé** : `KlassciEndpointClassificationGuardTest` (§4.1), dont la capacité à échouer a été vérifiée en dégradant volontairement `getEvaluations()`. |
| **LOW** garde jeton hors du `try` → `DecryptException` en 500 brut (`klassci_token` = accesseur sur colonne castée `encrypted`) | **Corrigé** : garde rentré dans le `try`, comme `ProxyDashboardController`. Régression introduite par la 1ʳᵉ version du correctif. |
| **LOW** aucun test multi-institutions (§1.3) | **Corrigé** : 5ᵉ cas de test, 2 institutions. |
| **LOW** prose du contrat redondante avec les `abstract` ; « même contrat » inexact ; `@throws` manquant | **Corrigés** (renvoi vers les `abstract`, « même statut », `@throws` ajouté). |
| **MEDIUM** extraire un trait `ResolvesPersonalKlassciToken` (4 sites) + constante du message 401 (10 sites) | **Non fait ici** — imposerait de toucher `ProxyDashboardController`, étranger à la fuite. L'auditeur lui-même recommande le suivi. → issue dédiée. |
| **LOW** `xxh3` tronqué à 64 bits porte la garantie d'isolation | **Non modifié** — pré-existant (#137), aucun vecteur pratique (jetons émis par KLASSCI, non choisis par l'attaquant). Signalé pour la trace. |
