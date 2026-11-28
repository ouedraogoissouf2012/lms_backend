# ADR-1868-01 — La spec OpenAPI : une racine et des fragments, assemblés (différé)

**Date :** 2026-11-28  
**Statut :** proposé, **différé** (à reprendre quand la file des PR serveur est courte)  
**Issue :** #1868 (listes partagées qui rouvrent les conflits). Les volets routes et tenancy sont traités à part.

## Le problème, mesuré

Le 28/11, sur `lms` 255e3435 et les 30 PR serveur ouvertes (conflits par `git merge-tree`, paire par paire) :

| fichier | PR qui le touchent | paires en conflit | PR en conflit avec `lms` à l'instant |
|---|---|---|---|
| `docs/openapi.yaml` | 25 | **69 / 300** | 5 |

- **608 blocs de conflit** dans ces 69 paires. Ils s'ancrent sur des chemins VOISINS (`/banque/categories`,
  `/evaluations/{id}/reclamations`, `/lms/classes/{classe}/resultats`…) : une fonctionnalité s'écrit près des
  chemins de son domaine, et deux fonctionnalités du même domaine se touchent.
- Le point « juste avant `/users:` » n'en est qu'une petite part : 2 des 39 commits de la spec depuis le 20/11 y
  insèrent.
- La spec fait 15 935 lignes : 122 chemins et 139 schémas, rangés sans ordre.

## Pistes écartées, par la mesure

1. **Insérer par ordre alphabétique** (chemins et schémas triés, garde de l'ordre). Sur les 25 PR ouvertes, **82
   paires** ajouteraient dans le MÊME intervalle trié, contre 69 conflits aujourd'hui : les nouveaux chemins d'un
   domaine partagent leur préfixe, donc leur intervalle. Pire qu'aujourd'hui.
2. **Découper par `$ref` vers des fichiers externes**, la spec restant le point d'entrée.
   - 41 fichiers de test lisent LA spec par `Yaml::parseFile(base_path('docs/openapi.yaml'))` et y attendent des
     chemins résolus.
   - `ValidatesAgainstOpenApi` ne résout pas les références externes (et ne connaît pas `allOf`).
   - `OpenApiConventionTest` exige une seule spec, servie par Swagger octet pour octet (#889).
   - Les scripts `openapi-refus-de-la-resolution.py` et `openapi-429-des-lectures.py` éditent un seul fichier.
   - La CI du front lit `docs/openapi.yaml` brut par `curl` depuis `lms`.
3. **Un fichier assemblé COMMIS** depuis des fragments. Le fichier assemblé reste partagé : il conflicte à chaque
   fusion, même si la résolution devient mécanique (réassembler).

## Décision proposée

- `docs/openapi.yaml` reste la **racine** : `openapi`, `info`, `servers`, `tags`, `securitySchemes`, les composants
  partagés (`ErrorResponse`, `TooManyRequests`, `EtablissementInconnu`…), et TOUT l'existant, qui ne bouge pas.
- Chaque **nouvelle** fonctionnalité écrit `docs/openapi/<issue>-<sujet>.yaml`, avec ses `paths` et ses
  `components`. Ses `$ref` sont internes (`#/components/schemas/X`) : ils visent le document assemblé.
- **Un assembleur**, `scripts/assembler-la-spec.py`, fusionne la racine et les fragments, triés par nom de
  fichier. Il **échoue** sur une clé en double (chemin, ou composant déjà défini ailleurs). Aucun fichier assemblé
  n'est commis.
- **Tous les consommateurs lisent l'assemblé** :
  - un chargeur PHP (`Tests\Concerns\SpecOpenApi`), qui remplace les 41 `Yaml::parseFile` de façon mécanique ;
  - `OpenApiSyncTest` et `OpenApiConventionTest`, adaptés : invariant 1, une racine et des fragments, une seule
    spec assemblée ; invariant 3, Swagger sert l'assemblé ;
  - Swagger, par une route qui sert l'assemblage ;
  - la CI : Docs Sync, génération des SDK, job « Front contract » et validateur, qui assemblent d'abord ;
  - la CI du front : extraction partielle (`sparse checkout`) de `docs/` et de l'assembleur, au lieu du `curl`
    du fichier brut.
- Les deux scripts de complétion (400/403, 429) s'appliquent au fragment de la fonctionnalité.

## Conséquences

- Les ajouts NEUFS ne partagent plus aucun fichier : un fragment par fonctionnalité. L'existant ne conflicte plus
  qu'en modification, ce qui est rare.
- Aucune vague de migration : on ne découpe pas les 15 935 lignes existantes.
- Le coût est réel, d'où le report : la CI des deux dépôts, les 41 tests et Swagger changent. À faire en une
  seule PR, quand la file des PR serveur est la plus courte, sous cycle D74. La preuve : la spec assemblée doit
  être identique, comme document, à `docs/openapi.yaml` avant la migration.
