# Requirements — #568 Cache KLASSCI partagé entre utilisateurs (`/auth/me`)

**Type** : hotfix sécurité (P0, sous-issue de #563). Bug isolé → lane
production-standards (CONTRIBUTING §A « Quand NE PAS utiliser [spec-workflow] :
Hotfix d'un bug isolé »). Ce document sert de traçabilité de la décision, pas de
gate d'approbation multi-phases.

## Contexte / racine

`AuthController::me()` (GET `/api/auth/me`) appelait
`KlassciProxyService::get('auth/me')`. La méthode `get()` génère une clé de cache
**tenant-globale** via `KlassciCacheKeyStrategy::generateGlobalKey()` —
`klassci_{tenant}_auth-me_{md5([])}_{invalidatedAt}` — **sans hash du token**.

Or `KlassciConfigResolver` résout `auth/me` avec le **token personnel** de
l'utilisateur connecté (priorité 1, `KlassciConfigResolver.php:141-163`). La
réponse KLASSCI est donc liée à l'identité du porteur du token, mais mise en
cache sous une clé partagée par tout le tenant.

**Conséquence** : le profil du 1er appelant est servi à tous les utilisateurs du
tenant → **fuite d'identité cross-user**.

## Exigences (EARS)

- **R1** — WHEN deux utilisateurs distincts d'un même tenant appellent
  `/api/auth/me`, le système SHALL retourner à chacun **son propre** profil
  KLASSCI, jamais celui d'un autre.
- **R2** — WHERE un utilisateur n'a **pas** de token personnel KLASSCI (compte
  auth locale / token institution), le système SHALL retourner un profil KLASSCI
  vide (`[]`) et NE SHALL PAS hériter du profil d'un autre utilisateur
  (fail-secure).
- **R3** — IF l'appel KLASSCI échoue (panne 5xx, transport), le système SHALL
  dégrader gracieusement (HTTP 200 + `klassci_data: []` + profil local) sans
  exposer de détail d'erreur (§1.2).
- **R4** — La garantie de whitelist #477 (KlassciDataWhitelist sur le payload
  LIVE) SHALL rester appliquée sur le chemin corrigé.
- **R5** — Le correctif SHALL être prouvé par un test TDD RED→GREEN démontrant
  l'isolation de bout en bout via le cache distribué réel.

## Critères de fermeture

`/auth/me` (et appels personnels) isolés par utilisateur, testé ; PR mergée dans
`lms`.
