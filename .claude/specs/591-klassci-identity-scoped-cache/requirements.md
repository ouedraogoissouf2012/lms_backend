# Requirements — #591 Cache KLASSCI partagé sur `evaluations` / `emploi-temps`

**Type** : hotfix sécurité (P1, sous-issue de #563 — dette adjacente relevée par
l'audit `spec-security` de #568). Bug isolé → lane production-standards
(CONTRIBUTING §A « Quand NE PAS utiliser [spec-workflow] : Hotfix d'un bug
isolé »). Ce document trace la décision ; il n'est pas une gate d'approbation
multi-phases.

---

## 1. Contexte / racine

`HasKlassciEndpointShortcuts::getEvaluations()` (`:122-125`) et
`::getEmploiTemps()` (`:131-134`) délèguent à `KlassciProxyService::get()`, dont
la clé de cache est produite par `KlassciCacheKeyStrategy::generateGlobalKey()` :

```
klassci_{tenantSlug}_{endpointSlug}_{md5(params)}_{invalidatedAt}
```

Cette clé **n'inclut pas l'identité du porteur du jeton**. Or la requête sortante
présente bien un porteur : `KlassciConfigResolver::resolve()` attache en priorité
1 le **jeton personnel** de l'utilisateur Sanctum courant
(`KlassciConfigResolver.php:141-163`).

**Conséquence** : la réponse du premier appelant est stockée sous une clé
partagée par tout le tenant, puis servie à tous les autres → **même classe de
fuite d'identité que #568** (`/auth/me`).

Clé réellement observée en test avant correctif (store `database`) :
`institution_none:klassci_default_evaluations_d751713988987e9331980363e24189ce_0`
— aucun segment lié au porteur.

### 1.1 Surface d'exposition (vérifiée)

`routes/api/core.php:73-94` — les deux routes vivent dans le groupe
`['auth:sanctum', 'klassci.sync', 'throttle:proxy']`, **sans restriction de
rôle** :

| Route | Contrôleur | Rôles admis |
|---|---|---|
| `GET /api/proxy/evaluations` | `ProxyAcademicController::evaluations` (`:41`) | tous (étudiant, enseignant, coordinateur, admin) |
| `GET /api/proxy/emploi-temps` | `ProxyAcademicController::emploiTemps` (`:55`) | tous |

Un étudiant et un enseignant du même tenant partagent donc la **même** entrée de
cache : la vue enseignant peut être servie à un étudiant (élévation de
privilège), ou l'inverse.

### 1.2 Preuve interne au dépôt que ces ressources sont *identity-scoped*

L'issue posait la confirmation KLASSCI comme préalable. Elle est **déjà tranchée
par la convention dominante du LMS lui-même** : partout ailleurs, ces deux
ressources sont consommées via la variante **par utilisateur**.

| Fichier:ligne | Appel |
|---|---|
| `app/Services/Evaluation/Student/StudentEvaluationsListService.php:77` | `requestWithUserToken($klassciToken, 'evaluations', 'GET')` |
| `app/Services/Evaluation/Student/EvaluationAttemptStateService.php:179` | `requestWithUserToken($klassciToken, 'evaluations', 'GET')` |
| `app/Services/Matiere/MatiereEvaluationsFetcher.php:72` | `requestWithUserToken($klassciToken, 'evaluations', 'GET')` |
| `app/Services/Matiere/MatiereInfoFetcher.php:102` | `requestWithUserToken($klassciToken, "emploi-temps?…", 'GET')` |

Les deux raccourcis globaux étaient les **seuls** points d'accès de tout le dépôt
traitant `evaluations` / `emploi-temps` comme des ressources tenant-partagées.
C'était une incohérence, pas un choix.

### 1.3 Argument indépendant de KLASSCI

Le cache distribué du LMS est un **cache partagé** au sens
[RFC 9111 §3.5](https://datatracker.ietf.org/doc/html/rfc9111#section-3.5) : une
réponse obtenue avec un en-tête `Authorization` ne doit pas être réutilisée pour
une requête ultérieure portant un autre porteur, sauf directive explicite du
serveur d'origine. Aucune directive de ce genre n'est lue ici. La clé partagée
viole donc la règle **quelle que soit** la réponse de KLASSCI (cf. §Q15 du
design : le correctif est correct sous les deux hypothèses).

---

## 2. Exigences (EARS)

- **R1** — WHEN deux utilisateurs distincts d'un même tenant appellent
  `GET /api/proxy/evaluations`, le système SHALL retourner à chacun la réponse
  KLASSCI **associée à son propre jeton**, jamais celle d'un autre.
- **R2** — WHEN deux utilisateurs distincts d'un même tenant appellent
  `GET /api/proxy/emploi-temps`, le système SHALL appliquer la même garantie
  que R1.
- **R3** — WHERE l'appelant n'a **pas** de jeton KLASSCI personnel, le système
  SHALL refuser la requête (401) et NE SHALL PAS lui servir la charge utile d'un
  autre utilisateur restée en cache (fail-secure, cf. R2 de #568).
- **R4** — Le système SHALL NE PAS retomber sur le jeton d'institution ou le
  jeton système pour ces deux ressources : une ressource liée à l'identité exige
  une identité. Un repli servirait à un compte sans identité KLASSCI la vue
  tenant-wide du jeton système.
- **R5** — Les 8 raccourcis réellement tenant-partagés (`getStructure`,
  `getClasses`, `getMatieres`, `getMatiereDetails`, `getEnseignants`,
  `getEnseignantsEnrichis`, `getFilieres`, `getNiveauxEtudes`) SHALL conserver
  leur clé globale (pas de dégradation du taux de hit sur des données identiques
  pour tout le tenant).
- **R6** — L'isolation SHALL être garantie **par le typage** et non par la
  vigilance de l'appelant : il SHALL être impossible d'appeler
  `getEvaluations()` / `getEmploiTemps()` sans fournir un porteur.
- **R7** — Le porteur utilisé pour la clé SHALL provenir d'une source **per
  requête par construction** (argument de méthode issu de l'objet `Request`), et
  non d'un collaborateur résolu au constructeur — cf. §3 du design (mémoïsation
  du contrôleur par Laravel).
- **R8** — Le correctif SHALL être prouvé par un test TDD RED→GREEN démontrant
  l'isolation de bout en bout **via le cache distribué réel**, avec une frontière
  de requête fidèle à la production.
- **R9** — L'audit des **autres** appels `get()` globaux SHALL être livré, avec
  verdict par appel ; tout risque résiduel hors périmètre SHALL être signalé
  comme dette tracée (jamais corrigé silencieusement, jamais tu).

### 2.1 Changement de comportement assumé (à signaler dans la PR)

R3/R4 introduisent un **401 là où la requête aboutissait auparavant** pour les
comptes sans jeton KLASSCI personnel (auth locale, ou compte à jeton
d'institution). C'est délibéré et aligné sur :

- `ProxyDashboardController::studentDashboard/teacherDashboard`, qui renvoient
  déjà exactement `401 « Token KLASSCI non trouvé. Veuillez vous reconnecter. »`
  sur les endpoints personnels du même préfixe `/api/proxy` ;
- le fail-secure R2 de #568 ;
- le constat que le comportement **actuel** pour ces comptes n'est pas « ça
  marche » mais « ils reçoivent les données d'autrui » — le 401 remplace une
  fuite, il ne supprime pas une fonctionnalité saine.

---

## 3. Hors périmètre (explicite)

- `getClasseEtudiants()` et les 8 raccourcis catalogue — voir l'audit §5 du
  design ; un risque résiduel y est **signalé** et proposé en issue de suivi,
  pas corrigé ici (discipline de périmètre, cf. instruction « ne déborde pas »).
- La mémoïsation des contrôleurs par le `Route` (§3 du design) — risque
  pré-existant, signalé pour la migration Octane #378, non corrigé ici.
- `requestWithUserToken()` — inchangé (déjà correct, ~50 appelants).

---

## 4. Critères de fermeture

1. `GET /api/proxy/evaluations` et `GET /api/proxy/emploi-temps` isolés par
   porteur, prouvé par test RED→GREEN.
2. Audit des autres `get()` publié dans la PR + commentaire d'issue.
3. Suite impactée verte + PHPStan 0 erreur.
4. PR mergée dans `lms`, issue #591 fermée avec le verdict de l'audit.
