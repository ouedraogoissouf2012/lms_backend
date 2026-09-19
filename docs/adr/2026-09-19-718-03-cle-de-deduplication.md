# ADR-718-03 — La clé de déduplication normalise les formes internationales, et refuse de deviner les nationales

**Date :** 2026-09-19  
**Statut :** accepté  
**Issue :** #718 · paie la dette tracée par ADR-718-02

## Décision

`PhoneNormalizer` remplace l'ancien `normalizePhone()` privé. La règle est étroite et déterministe :

| Écriture | Clé produite |
|---|---|
| `+22670000000` | `+22670000000` |
| `0022670000000` | `+22670000000` |
| `+226 70 00 00 00`, `+226-70-00-00-00` | `+22670000000` |
| `70000000`, `70 00 00 00` | `70000000` |
| `22670000000` (sans `+`) | `22670000000` |
| `à demander`, `+`, ` ` | `''` |

Autrement dit : **les formes internationales sont ramenées à E.164 ; les formes nationales sont laissées intactes.**

## Pourquoi

### La dette

ADR-718-02 l'avait écrite noir sur blanc :

> « La clé de déduplication n'est **pas** normalisée en E.164, alors que #718 la déclare ainsi. `normalizePhone()` retire tous les non-chiffres, y compris le `+` : `+22670000000` et `0022670000000` produisent deux clés, donc deux comptes pour la même personne. »

Or #718 fait de l'idempotence sur cette clé la propriété qui « permet à un formateur de corriger trois lignes et de **renvoyer tout le fichier** ». C'est précisément ce geste qui échouait dès qu'une ligne changeait d'écriture.

### Pourquoi la normalisation E.164 n'est PAS complète

E.164 exige un **indicatif pays**. Le dépôt n'en a aucune source, et c'est mesuré :

- aucune entrée de configuration (`grep` sur `config/` : rien) ;
- aucune colonne sur `institutions` — `id, slug, name, klassci_api_url, logo_url, primary_color, is_active, settings, …, mode` ;
- `config/app.php` : `locale = en`, `timezone = UTC`.

Et le parc est **mixte** : `esbtp-abidjan` est ivoirien (+225), les jeux d'essai du dépôt sont burkinabè (+226).

Choisir un indicatif par défaut le rendrait donc **faux pour une partie du parc, en silence** — et fusionnerait deux abonnés distincts portant le même numéro national sous une seule clé, c'est-à-dire sous un seul compte. **Deviner est ici pire que s'abstenir** : une fusion de comptes ne se répare pas en rejouant un fichier.

### Pourquoi `00` est traité, et pas `226` nu

`+` et `00` sont deux notations **du même préfixe d'accès international** : leur équivalence est une règle, pas une inférence. En revanche `22670000000` sans `+` peut aussi bien être un numéro national commençant par 226 : lui préfixer un `+` serait exactement deviner. Il reste donc tel quel.

### Pourquoi une cellule sans chiffre ne produit pas de clé

Une colonne « téléphone » contient parfois `à demander` ou `néant`. Si ces cellules produisaient une clé, **tous les apprenants sans numéro se fondraient en un seul compte** — la pire des fusions, et la plus silencieuse.

## Conséquences

- **Ce qui est réparé** : le même abonné écrit en `+226…` puis en `00226…` ne produit plus deux comptes. C'est le cas nommé dans la dette.
- **Ce qui ne l'est pas** : un numéro national et sa forme internationale restent **deux clés distinctes**. Un test le tient explicitement, pour que personne ne le découvre par surprise.
- **Ce qui est stocké change de forme.** `ImportApplyService` prend le téléphone du rapport pour chercher (`where('phone', …)`) **et** pour écrire. Les comptes créés désormais portent la forme normalisée ; ceux créés avant gardent leur forme brute. Un ré-import d'une personne déjà en base sous `70000000`, présenté cette fois en `+22670000000`, ne la retrouvera pas. Cette limite existait déjà — le changement ne l'aggrave pas, il la déplace vers une forme canonique.
- Un rapport produit **avant** ce lot porte des téléphones non normalisés ; sa confirmation les applique tels quels. Aucun import en attente n'a besoin d'être rejoué.

## La suite, si elle est voulue

Le jour où une institution portera son pays — colonne, configuration, ou déduit d'un champ existant —, `PhoneNormalizer` est **le seul endroit à reprendre**. Il est pur, sans base ni HTTP, et ses huit cas sont épinglés. Ajouter un indicatif par défaut y deviendrait une décision explicite, prise avec la donnée qui la rend correcte, au lieu d'une hypothèse enfouie dans une expression régulière.
