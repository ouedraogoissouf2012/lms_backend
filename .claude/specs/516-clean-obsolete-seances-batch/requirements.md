# Requirements — #516 [PERF][HIGH] CleanObsoleteSeances : requête non bornée tous-tenants + N+1 HTTP

## Contexte vérifié (code réel, HEAD 4a74320b)

`app/Jobs/CleanObsoleteSeances.php` a déjà été partiellement corrigé par #539
(commit `b9cfe2a7`, déjà mergé) pour le volet "requête non bornée" : la requête
utilise désormais `chunkById($this->drainChunkSize, ...)` + arrêt souple au
budget-temps (`InteractsWithDrainBudget`). **Les 2 défauts restants de l'issue
sont réels et intacts** :

1. **N+1 HTTP** (`:118-119` de la version actuelle) : `checkAndArchiveSeance()`
   fait 1 appel `requestWithUserToken()` séquentiel **par séance**, à l'intérieur
   du `foreach` de chaque lot `chunkById`.
2. **"Odeur d'isolation"** (`:55-64`) : un seul admin/coordinateur est choisi
   **arbitrairement** (`User::whereIn('role', ['coordinateur','admin'])->first()`,
   sans filtre `institution_id`) et son token sert à vérifier les séances de
   **tous les tenants confondus**, sans distinction d'institution dans la requête
   `Seance::where('is_active', true)->whereNotNull('klassci_seance_id')`.

## Découverte pendant l'investigation : le défaut #2 est plus grave que "une odeur"

Analyse du flux réel de résolution d'URL KLASSCI (`app/Services/Klassci/
KlassciConfigResolver.php` + `KlassciHttpClient::executeHttp:88,102`) :

- `requestWithUserToken($token, ...)` transmet `$token` **uniquement** comme
  override du header `Authorization` (`$token = $overrideToken ?? $this->config->token();`).
- **L'URL de base, elle, est TOUJOURS résolue via `KlassciConfigResolver`**
  (`$baseUrl = $this->config->requireBaseUrl();`), indépendamment du token
  passé en paramètre.
- `KlassciConfigResolver::resolve()` priorise (1) le token personnel de
  l'utilisateur **HTTP authentifié courant** (`auth->guard('sanctum')->user()`),
  (2) le token institution de CET utilisateur HTTP, (3) **le config système
  global** (`TenantManager::klassciConfig()`, qui sans `set()` explicite
  retourne `config('services.klassci.url')`).
- **Un job de queue n'a jamais d'utilisateur Sanctum authentifié** → priorités
  1 et 2 ne matchent jamais → priorité 3 systématique → **URL globale unique**,
  quel que soit le token passé à `requestWithUserToken()`.
- Vérifié que les institutions ont des URLs KLASSCI **réellement différentes**
  (`database/seeders/InstitutionSeeder.php:55,62,69` :
  `presentation.klassci.com`, `esbtp-abidjan.klassci.com`,
  `esbtp-yakro.klassci.com` — 3 domaines distincts).

**Conséquence concrète** : ce job envoie le token de l'admin choisi
arbitrairement vers l'URL globale, pour vérifier des `klassci_seance_id` qui
appartiennent en réalité à des institutions avec des URLs KLASSCI
**différentes**. Pour toute institution ≠ celle représentée par la config
globale, la vérification interroge le **mauvais backend** — la séance y est
introuvable par construction (elle n'y a jamais existé) → 404 → **archivage à
tort d'une séance encore bien active dans son vrai KLASSCI**.

**Ce défaut est pré-existant, pas introduit par ce correctif** — présent dans
le code actuel avant toute modification. Le mécanisme racine
(`KlassciConfigResolver`) est un fichier partagé hors du périmètre assigné
(`app/Services/Klassci/*` n'est pas sous `app/Jobs/CleanObsoleteSeances.php`
ni `app/Services/Seances/*`) — non modifié ici. **Signalé séparément à
l'orchestrateur** : le même défaut affecte potentiellement
`KlassciSeancesSyncService` (#515, déjà en PR #561) et tout autre job/command
qui appelle `requestWithUserToken()`/`fetchManyByEndpoint()` sans jamais
appeler `TenantManager::set()`.

## Mécanisme de correction disponible, déjà documenté pour cet usage

`TenantManager::set(Institution $institution)` existe précisément pour ce cas
— son propre docblock (`app/Services/TenantManager.php:22-26`) : *"Utilisé
dans : ... Les jobs/commands d'administration qui veulent forcer un état
propre avant de fixer un tenant cible."* Une fois appelé, `KlassciConfigResolver`
priorité 3 résout l'URL **et** le token de **cette** institution
(`$institution->getKlassciConfig()`, qui lit `klassci_api_url` +
`klassci_api_token` — accessor déjà décrypté, cast `encrypted`). Plus besoin
de choisir un admin arbitraire : chaque institution porte déjà son propre
couple URL+token système.

## Décision architecturale

Grouper les séances par `institution_id`. Pour chaque institution (avec un
`klassci_api_url`/`klassci_api_token` exploitables) : `TenantManager::set($institution)`,
puis vérifier ses séances en **batch** (pool HTTP parallèle) plutôt que
séquentiellement.

**Pourquoi ne pas réutiliser `KlassciBatchFetcher::fetchManyByEndpoint()` tel
quel** (Q12 self-critique — alternative explorée et rejetée) : cette méthode
retourne une map `[id => payload]` où les IDs en échec sont **silencieusement
omis**, sans distinction entre "404 confirmé" (séance réellement supprimée
côté KLASSCI → archivage légitime) et "erreur transitoire" (réseau, 5xx →
NE PAS archiver). Le code actuel de `checkAndArchiveSeance()` fait
explicitement cette distinction (`str_contains($e->getMessage(), '404')`).
Réutiliser `fetchManyByEndpoint()` naïvement transformerait TOUTE erreur
(pas seulement les 404) en archivage à tort — une régression de correction
inacceptable pour un mécanisme dont la seule fonction est de décider quoi
archiver. Retenu : un collaborateur dédié, dans le périmètre assigné, qui
préserve cette distinction à trois états (existe / confirmé supprimé / erreur).

## Exigences (format EARS)

**R1 — Vérification scopée par institution avec le bon backend KLASSCI**
QUAND le job vérifie les séances actives d'une institution, ALORS il DOIT
utiliser l'URL et le token KLASSCI propres à **cette** institution
(`TenantManager::set()` + `Institution::getKlassciConfig()`), jamais un token
d'un autre tenant ni la configuration système globale par défaut.

**R2 — Institutions sans configuration KLASSCI exploitable ignorées proprement**
SI une institution n'a pas d'`klassci_api_url`/`klassci_api_token` exploitable,
ALORS ses séances actives NE DOIVENT PAS être vérifiées ce run (ni archivées
à tort faute de configuration) — loggé en warning, le job continue avec les
autres institutions.

**R3 — Vérification batchée (élimine le N+1 HTTP)**
QUAND le job vérifie les séances d'une institution, ALORS les appels
`seances/{id}` DOIVENT être exécutés en pool HTTP parallèle (plusieurs par
lot), jamais un par un de façon séquentielle.

**R4 — Distinction confirmée entre "supprimé" et "erreur"**
LE mécanisme de vérification batchée DOIT distinguer explicitement, par ID :
« existe » (200), « confirmé supprimé » (404), « erreur » (tout le reste :
timeout, 5xx, connexion). Seul un 404 confirmé déclenche l'archivage —
jamais une erreur transitoire.

**R5 — Budget-temps et chunking (#539) préservés**
LE mécanisme de budget-temps souple + `chunkById` existant DOIT rester actif,
désormais appliqué par institution (une institution peut être partiellement
traitée si le budget est atteint en cours de route ; job idempotent, reprend
au run suivant — comportement inchangé, juste réparti différemment).

## Hors périmètre (explicitement écarté, avec raison)

- **Corriger `KlassciConfigResolver`/`KlassciHttpClient`** — fichier partagé
  hors du domaine assigné ; le défaut racine touche potentiellement d'autres
  jobs (#515 y compris) et mérite sa propre issue transverse, pas un correctif
  local à `CleanObsoleteSeances`.
- **Modifier `KlassciBatchFetcher`** — idem, hors domaine ; d'ailleurs son
  comportement "omission silencieuse" est correct pour SES cas d'usage
  (préchargement d'affichage), le problème est spécifique à un usage
  "décision d'archivage" que ce correctif introduit dans un nouveau
  collaborateur dédié plutôt que d'élargir la sémantique d'un composant partagé.

## Vérification

Tests : (1) séances de 2 institutions différentes avec des `klassci_api_url`
distinctes → chaque institution vérifiée avec SA propre config (mock
`TenantManager`/`KlassciConfigResolver` ou assertion sur les requêtes HTTP
mockées par tenant) ; (2) une institution sans config KLASSCI → ignorée sans
crash, warning loggé ; (3) test anti-N+1 : assertion sur un appel batché
(pool) plutôt que N appels séquentiels ; (4) 404 confirmé → archivé ; erreur
générique → PAS archivé (test qui aurait échoué avec l'ancienne détection par
sous-chaîne `str_contains($e->getMessage(), '404')`, remplacée par un vrai
statut HTTP).
