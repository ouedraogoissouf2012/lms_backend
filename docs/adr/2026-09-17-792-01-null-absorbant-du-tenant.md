# ADR-792-01 — Le `null` d'un tenant résolu est absorbant

**Date :** 2026-09-17  
**Statut :** accepté  
**Issue :** #792 · épique #805 · bloque #802

## Décision

`KlassciConfigResolver` ne se rabat plus sur `config('services.klassci.url')` / `.token` en priorité 3. Les deux replis sont **supprimés** :

```diff
- $configUrl   = $config['url']   ?? config('services.klassci.url');
- $configToken = $config['token'] ?? config('services.klassci.token');
+ $configUrl   = $config['url'];
+ $configToken = $config['token'];
```

Dès qu'un tenant est résolu, sa configuration fait foi — y compris quand elle vaut `null`. Le repli global subsiste, mais **au seul endroit qui a le droit de le décider** : `TenantManager::klassciConfig()`, quand aucune institution n'est résolue.

## Pourquoi

### Le défaut

`TenantManager::klassciConfig()` (`:104-114`) fait déjà la bonne chose :

```php
if ($this->current) {
    return $this->current->getKlassciConfig();   // ['url' => null, ...] si autonome
}

return ['url' => config('services.klassci.url'), 'token' => config('services.klassci.token')];
```

Le `??` du résolveur **confondait deux états distincts** :

| État | Ce que rend `klassciConfig()` | Ce que faisait le `??` |
|---|---|---|
| aucun tenant résolu | la config globale | rien — déjà globale |
| tenant résolu **avec** URL | l'URL du tenant | rien |
| tenant résolu **sans** URL (autonome) | `null` | **écrasait par la config globale** |

**Conséquence.** Une école autonome n'était pas « en mode local » : elle héritait de la cible KLASSCI du serveur, et y lisait — et écrivait — avec le jeton système. Les seeders pointent `presentation.klassci.com`. Si l'environnement serveur est vide, elle recevait un 503 au lieu de fonctionner en local.

Tant que ce verrou tenait, la cohabitation des deux mondes était **structurellement impossible** : ils partageaient la même cible.

### Pourquoi une suppression, et pas un test supplémentaire

La première idée était d'interroger `TenantManager::get()` pour distinguer « tenant résolu » de « pas de tenant ». C'était inutile : **`klassciConfig()` porte déjà cette distinction**, et applique déjà le repli global sur la branche où il est légitime. Le `??` du résolveur était donc **redondant sur un chemin et nuisible sur l'autre**.

Supprimer bat ajouter : la correction retire un concept dupliqué au lieu d'en introduire un second, et elle ne touche pas la surface de `TenantManager` — donc aucun test existant n'a à apprendre un nouvel appel.

### La suppression vaut aussi pour le JETON, et c'est le cas le plus insidieux

Trouvé en relisant le diff, pas en l'écrivant : le second `??` portait sur `token`, et mes quatre premiers tests ne parlaient que d'URL.

Le cas est atteignable **depuis l'écran** — `'klassci_api_token' => 'nullable|string'` dans `InstitutionController`. Une institution qui déclare une URL **sans** jeton empruntait donc le jeton système du serveur pour parler à **sa propre** cible : c'est envoyer les identifiants du serveur à un hôte tiers.

C'est exactement la fuite que la priorité 2 refuse déjà par ailleurs — son commentaire de sécurité (#75) rappelle que « deux institutions peuvent partager le même serveur KLASSCI ; un lookup par URL est ambigu et exposait à une fuite cross-institution ». Le repli de priorité 3 rouvrait cette porte par un autre chemin.

Deux tests couvrent désormais cette moitié : une école autonome n'emprunte pas le jeton, et une école avec URL mais sans jeton garde sa cible **sans** recevoir celui du serveur.

### Le périmètre est de deux lignes, et c'est mesuré

Balayage de `config('services.klassci` dans `app/` — 23 occurrences, dont :

- **2** lisent l'URL/le jeton comme **cible** : `KlassciConfigResolver:215-216`, le défaut corrigé ici ;
- **2** sont le repli légitime de `TenantManager:111-112`, inscrit à l'allow-list OCP comme « l'accroche prévue du mode, pas un contournement » ;
- **1** est `ProxyOrganisationController:190`, déjà tracée comme **dette** dans `.ocp-allowlist.json` (réponse de diagnostic) ;
- les **18** restantes sont de l'infrastructure — `ssl_verify`, `timeout`, `pool_size`, `retry_after`, `memoize_enabled` — que la garde OCP exclut explicitement de son motif.

Aucun autre point de repli n'existe. La correction est complète, pas partielle.

## Un test existant qui ne prouvait pas ce qu'il annonçait

`KlassciConfigResolverUrlGuardTest::test_require_base_url_throws_when_url_is_null` passe `url => null` et attend `KlassciUnavailableException`. Il est vert aujourd'hui **par accident de configuration** : `phpunit.xml` ne définit pas `KLASSCI_API_URL`, si bien que `null ?? config(...)` rendait `null` lui aussi.

Dans un environnement où l'URL est renseignée — la production — le même test aurait échoué, parce que le repli aurait fourni une URL valide. Il mesurait l'absence de configuration du runner, pas la garde.

La suppression du `??` rend son verdict **indépendant de l'environnement**. C'est un effet de bord du correctif, et il valait d'être nommé.

## Conséquences

- Une institution sans `klassci_api_url` ne produit **aucune requête sortante** : la preuve est un `Http::fake()` avec assertion de zéro appel, comme le demande #792.
- Elle reçoit un 503 explicite si un chemin exige quand même KLASSCI (`requireBaseUrl()`), au lieu d'atteindre silencieusement l'école d'un tiers. **Échouer bruyamment bat réussir sur la mauvaise cible.**
- Le supradmin et les routes publiques, qui s'exécutent sans tenant résolu, gardent la config globale — leur chemin est inchangé.
- #802 est débloquée : sa jambe autonome peut enfin prouver quelque chose, ce qui était impossible tant que les deux mondes partageaient la cible.

## Hors périmètre

Le discriminant reste `klassci_api_url IS NULL`, et non la colonne `mode` — bien que celle-ci existe depuis #814 et soit déclarable depuis #818. Deux raisons : le mode ne se lit **qu'au point de liaison** `RosterAuthorityFactory` (article 1 de #697), et la nullité de l'URL est déjà le discriminant lu par `LoginOrchestrator`, `KlassciTenantDiscovery`, `ProbeKlassciReachabilityCommand` et `InstitutionConnectionTester`. Introduire ici une seconde source de vérité les ferait diverger.

La refonte de ces appelants n'est pas couverte. `ProxyOrganisationController:190` reste la dette qu'elle était.
