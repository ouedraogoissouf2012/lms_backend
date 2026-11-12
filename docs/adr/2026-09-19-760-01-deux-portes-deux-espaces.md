# ADR-760-01 — Deux portes quand une ressource est désignée dans deux espaces

**Date :** 2026-09-19  
**Statut :** accepté, amendé le 2026-11-09 ([ADR-EI-01](2026-10-30-ecole-independante-01-meme-route-autre-source.md), lot 2)  
**Issue :** #760 (fermée) · PR #874 (`926963db`) · prolonge [ADR-805-01](2026-09-19-805-01-resolution-des-identifiants.md)

> **Amendement EI-02 du 2026-11-09 — une école créée depuis le LMS n'a qu'un espace.**
>
> La source de chaque porte est choisie par le mode DÉCLARÉ de l'école, au point de liaison
> (`RosterAuthorityFactory::sourceForCurrentTenant`), jamais devinée à partir de l'entier reçu.
>
> - **École créée depuis KLASSCI** : les espaces ne changent pas. La porte historique attend l'espace
>   KLASSCI, la porte locale traduit, et le 409 « créée localement » reste rendu pour une classe sans
>   `klassci_id`. Seul le rendu des échecs change : les deux portes relaient les refus de KLASSCI
>   (4xx) et rendent ses pannes en 503 avec `Retry-After`, au lieu d'un 500.
> - **École créée depuis le LMS** : les deux portes servent la même fiche, lue dans les tables
>   locales, pour l'id LOCAL, sans 409. La prémisse de cet ADR, « une ressource désignée dans deux
>   espaces », n'y vaut pas : `klassci_id` y est NULL partout.
> - **École basculée** (créée depuis KLASSCI, passée en mode autonome) : elle suit son mode déclaré,
>   comme une école créée depuis le LMS, mais ses classes gardent leur `klassci_id`. Depuis le lot 3a, la liste d'administration
>   (`/proxy/classes`) suit le mode, et donne les identifiants LOCAUX que lisent les deux portes.
>   La dette tracée au lot 2 est fermée ; voir ADR-EI-01.
>
> La règle de cet ADR, « on n'arbitre pas à l'entrée », tient dans tous les cas : rien n'est deviné.
> Garder KLASSCI sur la porte historique d'une école basculée lirait le mode hors du point de
> liaison : écarté.

## Décision

Quand une même ressource est désignée par des appelants qui parlent **deux espaces d'identifiants différents**, on n'arbitre pas à l'entrée : **chaque espace a sa porte**.

Les portes traduisent au seuil et délèguent à **un service unique**. Aucune ne devine.

Premier cas appliqué :

| Porte | Espace attendu | Traduction au seuil |
|---|---|---|
| `GET /lms/classes/{classeId}` | KLASSCI | aucune — passe-plat |
| `GET /lms/classes/local/{classeId}` | LOCAL | `local → klassci_id` |

Toutes deux servent `ClasseDetailsQueryService`, inchangé.

## Pourquoi

### Arbitrer à l'entrée casse les appelants majoritaires

`/lms/classes/{id}` reçoit **trois** appelants, dans **deux** espaces :

| Appelant | Source de l'identifiant | Espace |
|---|---|---|
| `useAdminClasses.js:234` | `klassciService.getClasses()`, passe-plat brut | KLASSCI |
| `TeacherClassCard.vue:4` | `/lms/teacher/classes` | KLASSCI |
| `useMatiereDetails.js:255` | `classes_concernees` | LOCAL |

L'issue prescrivait de résoudre l'entrée par `Classe::localIdFor()`. Cette méthode donne la **précédence au local** ; son propre docblock avertit qu'« elle rendrait la ligne de l'autre espace, silencieusement ».

**Mesuré en production le 19/09/2026 :** les identifiants local et KLASSCI divergent, avec des collisions franches. L'arbitrage aurait donc détourné les deux appelants KLASSCI vers la mauvaise classe. **Deux chemins cassés pour un réparé.**

La leçon générale : *un résolveur à précédence est le bon outil quand l'espace est réellement inconnu. Quand on connaît les appelants et qu'ils sont majoritairement d'un espace, arbitrer revient à se tromper sur la majorité.*

### Émettre deux identifiants est interdit, et à raison

L'autre correctif évident — faire porter à la charge utile un second identifiant — est ce que `MatiereClassesResolver` a été corrigé pour **cesser** de faire. Son docblock : « en émettre deux, c'est fabriquer soi-même l'ambiguïté ».

Trois tests figent sa forme exacte par `assertSame` (`MatiereClassesFromMirrorTest:85`, `:102`, `MatiereClassesFromKlassciTest:265`), et `TeacherClassesLiveSourceTest:42` a déjà arbitré la tension entre les deux endpoints.

La règle actée du dépôt tient : **l'API n'émet que l'espace qu'elle stocke ; elle accepte les deux en entrée.** Une porte par espace est la seule forme qui l'honore *sans* deviner.

### La porte est un seuil, pas une couche

La traduction vit dans `ClasseLocalDetailsService`, qui ne fait que cela puis délègue. Le service métier n'apprend pas qu'il existe deux espaces — il continue de n'en connaître qu'un. C'est ce qui empêche la notion de se diffuser.

Forme empruntée à [ADR-803-03](2026-09-15-803-03-trois-portes-un-service.md) : plusieurs entrées, un seul service. Là c'étaient trois écritures ; ici deux lectures. La raison est la même — **N chemins qui décident chacun, ce sont N politiques qui divergent.**

## Ce que la porte locale décide en propre

- **404** quand la classe n'existe pas dans l'institution de l'appelant.
- **409** quand elle existe mais n'a pas de `klassci_id` — cas d'une classe créée localement ([ADR-848-01](2026-09-18-848-01-catalogue-pedagogique-local.md)). Répondre 404 mentirait : la classe est là, c'est la question qui n'a pas de sens pour elle. **Aucun appel sortant n'est émis.**
- **Bornage tenant explicite**, jamais délégué au scope global : celui-ci est *fail-open*, donc un compte plateforme lirait la classe de n'importe quel établissement. Sans institution, la porte ne résout rien — le `null` absorbant d'[ADR-805-01](2026-09-19-805-01-resolution-des-identifiants.md).

## Comment on le vérifie

L'assertion porte sur **l'URL envoyée à KLASSCI**, jamais sur le code HTTP : la porte fautive rend 200 elle aussi, avec la fiche d'une autre classe. Le seul témoin honnête est l'identifiant transmis en aval.

`ClasseLocalDetailsDoorTest` porte **quatre** cas. **Trois** gardent une promesse et ont été falsifiés un à un — traduction supprimée, bornage retiré, garde 409 désarmé : chacun rougit. Le quatrième ne garde rien, il **prouve par contraste** que la porte historique transmet le nombre tel quel — sans lui, rien n'établirait que les deux portes diffèrent vraiment.

## Où lire le reste

Ce fichier est le point d'entrée. Le détail vit ailleurs, et c'est délibéré :

| Support | Ce qu'il porte |
|---|---|
| `ClasseLocalDetailsService` (docblock) | la décision, au point d'usage |
| Commit `926963db` / PR #874 | le raisonnement complet, les deux correctifs rejetés |
| Commentaire sur #760 | pourquoi la prescription de l'issue était dangereuse |
| `ClasseLocalDetailsDoorTest` | les décisions sous forme exécutable |

## Ce que cette décision ne règle pas

- **Le front n'est pas basculé.** Tant que `useMatiereDetails.js:255` n'appelle pas la porte locale, le défaut persiste à l'écran. La PR backend seule ne corrige rien de visible.
- **`classes/{classeId}/etudiants` a le même défaut** — confirmé, il part lui aussi brut chez KLASSCI. Il lui faut sa porte, sur ce motif.
- **La chaîne vers le front reste manuelle.** La route est documentée dans `docs/openapi.yaml` et la CI l'impose, mais `src/services/endpoints.js` est écrit à la main : rien ne portera cette URL jusqu'au front. C'est un défaut d'outillage, tracé à part.
