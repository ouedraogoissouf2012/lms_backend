# ADR-805-01 — Résoudre un identifiant dual : une seule implémentation, un tenant qui absorbe

**Date :** 2026-09-19  
**Statut :** accepté  
**Issue :** épique #805 · prolonge [ADR-792-01](2026-09-17-792-01-null-absorbant-du-tenant.md)

## Décision

Tout site qui traduit un identifiant pouvant venir de **deux espaces** — local ou KLASSCI — passe par le modèle, jamais par une requête écrite à la main :

| L'espace de l'entrée est… | Méthode | Arbitrage |
|---|---|---|
| **inconnu** (une requête cliente) | `localIdFor()` | le local gagne |
| **connu** (un payload KLASSCI) | `localIdForKlassciId()` | le miroir, sans deviner |

La forme `where(id)->orWhere(klassci_*)` — et sa symétrique — est **bannie**, et un cliquet la refuse en CI.

**Le bornage reste `$user->institution_id`.** Et **l'absence de tenant ne résout RIEN** : pas « tout », pas « au hasard ». C'est le `null` absorbant d'ADR-792-01, appliqué aux identifiants.

## Pourquoi

### Le défaut n'est pas un bug, c'est un tirage au sort

`orWhere` laisse le **moteur** choisir la ligne quand les deux branches matchent. Rien n'interdit que l'`id` local d'une séance égale le `klassci_seance_id` d'une autre : ce sont deux numérotations indépendantes, et la seconde devient massivement peuplée à mesure que des lignes purement locales apparaissent — `seances.klassci_seance_id` est nullable depuis `2026_09_06_010000`, `matieres.klassci_id` depuis [ADR-848-01](2026-09-18-848-01-catalogue-pedagogique-local.md).

Le défaut est donc **en croissance** : chaque pas vers l'autonomie agrandit la zone de collision.

### Ce que j'ai cru, et ce que la mesure a dit

J'ai d'abord décidé que les correctifs devaient fournir le **tenant résolu** (`TenantManager::id()`) plutôt que `$user->institution_id`, au motif qu'un supradmin passe non scopé.

**C'est faux.** Mesuré dans `ResolveInstitution` :

- le tenant n'est peuplé que depuis l'`institution_id` du jeton (`:134`) ;
- le repli par en-tête `X-Institution` n'est atteint **que sans jeton** (`:63-69`), donc jamais sur une requête authentifiée.

Sur toute requête authentifiée, les deux valeurs sont **égales**, nulles comprises. Changer de source aurait été un no-op — et, présenté comme un durcissement, il aurait fait passer douze correctifs pour une correction de sécurité qu'ils ne sont pas.

C'est écrit ici parce qu'un ADR qui tait ses hypothèses réfutées condamne le suivant à les refaire.

### Ce qui, en revanche, est vrai

Sans tenant, `mirroredScope()` pose `where('institution_id', null)` : un supradmin ne résout **que** les lignes orphelines. C'est **fail-closed**, et c'est le bon défaut — l'inverse, une requête non bornée, franchirait la frontière du tenant que le trait existe pour tenir.

La conséquence fonctionnelle est réelle : **un supradmin ne peut résoudre aucune séance d'établissement**. Ce n'est pas une fuite, c'est un mur. Il est **hors périmètre** de cette campagne, et doit être tracé plutôt que contourné par une requête non bornée.

### Pourquoi treize correctifs et non un seul

Le trait existe depuis #707 ; les sites fautifs ne l'utilisent pas. Les regrouper en une PR rendrait impossible de dire quel changement a cassé quel parcours — et ces sites portent la chaîne visio, les présences et la validation des participants. L'ordre est **par risque décroissant** : suppression, désactivation, puis lecture.

## Les instruments, posés avant le premier correctif

Cette PR **ne modifie aucune ligne de `app/`**. Elle pose de quoi rendre les douze suivantes vérifiables :

1. **Le cliquet élargi** — `SingleMirroredIdentifierResolverTest` ne bannissait qu'une chaîne littérale, `orWhere('klassci_id'`. Il ne voyait donc **aucun** site de ce chantier : les colonnes miroir ne s'appellent pas toutes `klassci_id`, et la forme inversée s'étale sur deux lignes. Il passait au vert avec quatre sites fautifs vivants. Il porte désormais deux expressions et une baseline nominative de ces quatre-là, chacun étiqueté du rang de la PR qui l'en retirera.

2. **Le harnais de collision** — `SeanceIdentifierCollisionTest` provoque le cas que personne ne savait reproduire : deux lignes, un nombre, deux significations. Sans lui, chaque correctif aurait affirmé supprimer une indétermination qu'aucun test ne savait montrer.

3. **Cet ADR** — le bornage et son `null` absorbant.

Éprouvé sur `Seance`, seule entité duale dont la colonne miroir ne s'appelle pas `klassci_id` : c'est donc la seule qui exerce réellement la surcharge `colonneMiroir()` posée par #864.

## Ce que valent ces tests

Falsifiés, pas seulement exécutés. Le cliquet : forme directe neuve → rouge ; forme inversée neuve → rouge ; même motif **en commentaire** → vert, donc pas de faux positif ; entrée de baseline retirée → rouge. Le harnais : précédence inversée → rouge ; bornage tenant retiré → rouge ; espace connu qui devine → rouge.

Un vert sous SQLite ne prouve rien ici — une requête sur une colonne inexistante y est silencieusement vraie. **Le verdict appartient à la jambe MySQL de la CI.**

## Hors périmètre

- Le mur du supradmin, décrit plus haut.
- `SeancesHistoryQueryService:86` — `where('klassci_enseignant_id', $a)->orWhere('klassci_enseignant_id', $b)` porte la **même** colonne pour deux valeurs de l'identité de l'utilisateur. Ce n'est pas la faute visée ; les motifs du cliquet ont été resserrés pour ne pas le signaler.
- La refonte des appelants qui passent déjà `$user->institution_id` : ils sont corrects, et le resteront.
