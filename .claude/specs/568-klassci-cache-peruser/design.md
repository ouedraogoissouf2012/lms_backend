# Design — #568 Isolation par utilisateur de `/auth/me`

## Solution retenue (une seule, §6)

Basculer l'appel `/auth/me` du contrôleur de la variante **globale** (`get()`)
vers la variante **par utilisateur** (`requestWithUserToken()`), qui existe déjà
et dérive la clé cache du hash du token (`generateUserTokenKey()` →
`klassci_{tenant}_{tokenHash}_auth-me_...`). `xxh3(tokenA) ≠ xxh3(tokenB)` →
clés disjointes → isolation cross-user **par construction**.

`AuthController::me()` délègue à un helper privé
`fetchLiveKlassciProfile(User $user): array` :

```
me()  ── authenticate ──▶ fetchLiveKlassciProfile(user) ──▶ whitelist(#477) ──▶ present
                              │
                              ├─ pas de token perso ──▶ [] (fail-secure, R2)
                              ├─ requestWithUserToken(token,'auth/me','GET') (R1)
                              └─ panne KLASSCI ──▶ [] (dégradation, R3)
```

Le helper isole la responsabilité « récupérer le profil KLASSCI live isolé »
(SRP) et maintient `me()` en orchestrateur fin. Aucune signature de constructeur
modifiée (blast radius nul côté DI).

### Pourquoi c'est la racine et pas le symptôme

Le défaut n'est pas « on cache » mais « on cache une réponse liée à l'identité
sous une clé qui n'inclut pas l'identité ». La variante user-token corrige
exactement l'entrée manquante de la clé (le `tokenHash`), sur les deux couches
(memo intra-request ET cache distribué).

## Alternatives écartées (Q12)

1. **Ne pas cacher `/auth/me` (TTL=0 / bypass)** — rejetée : perd le bénéfice
   PERF-02 (chaque requête frontend re-frappe KLASSCI) alors que le vrai
   problème est la *clé*, pas le fait de cacher. La variante user-token cache
   correctement, par utilisateur.
2. **Rendre `get()` global « intelligent » (injecter le tokenHash quand un token
   perso est résolu)** — rejetée : rend le comportement implicite et surprenant,
   casse l'isolation intentionnelle des shortcuts tenant-partagés
   (`getStructure`/`getClasses`/…), et élargit le blast radius à un chemin très
   large. Le pattern explicite `requestWithUserToken` existe précisément pour ça
   (SRP/OCP).
3. **Extraire un collaborateur `KlassciSelfProfileClient` partagé avec le
   middleware `EnsureKlassciSync`** — rejetée pour ce P0 : élargirait le diff à
   un middleware sécurité-critique (CRITICAL-05 #34) hors périmètre. Dette
   tracée → issue de suivi (cf. §Dette).

## Test (stratégie)

`AuthMePerUserIsolationTest` feinte **uniquement la frontière réseau** :
remplace `Illuminate\Http\Client\Factory` (injectée dans le vrai
`KlassciHttpClient` — seam DIP) par une factory `fake()`. Tout le reste est réel
(service, stratégie de clés, cache distribué store `database`, memo). Entre les
deux requêtes, `forgetScopedInstances()` purge memo/tenant → **le cache
distribué est le seul état partagé**, exactement le vecteur de la fuite.

3 cas : (R1) 2 users même tenant → chacun son profil ; (R2) sans token → `[]` ;
(R3) panne 5xx → `[]` + 200. RED prouvé sur l'ancien code (Bob recevait
« Alice »), GREEN après correctif.

## Audit du périmètre (autres appels `get()` personnels)

- **`AuthController::me()`** — SEUL endpoint personnel routé via `get()` global.
  Corrigé.
- **`DispatchLessonPublishedNotifications::get('/matieres/{id}')`** — job worker
  sans user Sanctum → token système (priorité 3), donnée catalogue tenant-wide.
  Cache global **correct**.
- **Shortcuts `getStructure/getClasses/getMatieres/getEnseignants/…`** — données
  organisationnelles tenant-partagées (identiques pour tout le tenant). Cache
  global **correct**.

## Dette tracée / risque adjacent (hors-scope #568)

L'audit sécurité a relevé un **risque de même famille, pré-existant** :
`getEvaluations()` / `getEmploiTemps()`
(`HasKlassciEndpointShortcuts.php:122-133`, via `ProxyAcademicController`)
passent par `get()` global mais sont appelés avec le token personnel de
l'appelant. **Si** KLASSCI scope ces réponses par identité/rôle du porteur, on
aurait la même classe de fuite que #568. Non prouvable depuis le code LMS seul.

→ **Action** : ouvrir une issue de suivi pour confirmer côté KLASSCI ; si
identity-scoped, basculer sur `requestWithUserToken`. **Ne PAS traiter dans ce
P0** (scope discipline, nécessite confirmation externe).

## Conformité

- §1.1 AuthController 299 lignes physiques (≤300). §5 `me()` 11 l., helper 13 l.
  (≤40). §1.6 SRP/DIP/LSP respectés (mockable sans contournement, prouvé).
- PHPStan level 9 + baseline : 0 erreur (l'accès `data.user` sur `mixed`
  pré-baseliné reste apparié : même message, même count, même path).
- Audits `spec-security` (PASS) + `spec-architect` (PASS), 0 CRITICAL/HIGH.
