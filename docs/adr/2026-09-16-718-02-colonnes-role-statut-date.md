# ADR-718-02 — Les colonnes `role`, `statut` et `date_inscription` sont honorées, sous plafond

**Date :** 2026-09-16  
**Statut :** accepté  
**Issue :** #718 (backend) · #334 (frontend)

## Décision

`ColumnMap::CANONICAL_FIELDS` passe de cinq à huit champs : `role`, `date_inscription` et `statut` rejoignent `nom`, `prenom`, `email`, `telephone` et `code_classe`. Chacune est lue, validée à l'analyse à blanc, et écrite à l'exécution :

| Colonne | Destination | Défaut si la colonne manque |
|---|---|---|
| `role` | `users.role`, **à la création seule** | `etudiant` |
| `statut` | `classe_etudiant.statut` | `actif` |
| `date_inscription` | `classe_etudiant.date_inscription` | date du jour de l'exécution |

Quatre règles encadrent ces lectures.

**1. Le rôle est plafonné par celui de l'importateur.** Un compte créé n'est jamais plus permissif que la personne qui importe, au sens de `Role::isMorePermissiveThan()` (#121). Une ligne qui demande davantage est **refusée** (`role_interdit`), pas rabattue.

**2. `supradmin` est refusé sans condition** (`role_plateforme`), y compris à un supradmin. C'est le rôle plateforme, cross-tenant, et il ne naît que du seeder — même politique qu'à la création via KLASSCI (#510).

**3. Une valeur incomprise refuse sa ligne, elle ne prend pas le défaut.** `role_inconnu`, `statut_inconnu`, `date_invalide` échouent à l'analyse à blanc, avant toute écriture.

**4. Le plafond est relu à l'exécution**, depuis `imports.user_id`, et non repris du rapport.

L'ordre jour-mois est déclaré pour les dates : `15/09/2026` est le 15 septembre. Formats acceptés : `d/m/Y`, `d-m-Y`, `Y-m-d`.

## Pourquoi

### Le produit promettait ces colonnes et le serveur les jetait

Le modèle CSV téléchargeable annonce huit colonnes (`src/constants/importFields.js:22`), l'écran de cartographie les propose toutes les huit (`:9-12`), et `autoMap` les rattache seule par synonymes (`src/utils/importMapping.js:27-29`). Côté serveur, `ColumnMap::fromRequest()` n'itérait que sur `CANONICAL_FIELDS` : toute autre clé tombait **sans erreur ni avertissement**.

Le parcours réel, mesuré : une école télécharge le modèle **du produit**, remplit `role` et `statut`, voit les colonnes correctement appariées, lit « N acceptées » — et chaque compte naît `etudiant` (`ImportApplyService:70` avant ce changement), chaque inscription `actif` (`:89`), sans date. Un enseignant importé devenait élève, en silence.

Le silence est le défaut central : « N acceptées » sur un fichier dont un tiers du contenu a été jeté est un feu vert mensonger, et c'est exactement ce qu'une analyse à blanc existe pour empêcher (« une erreur d'import ne doit jamais obliger à ouvrir la base », #718).

### Pourquoi un plafond, et pas une liste blanche fixe

La cellule vient d'un tableur : c'est une donnée d'entrée comme une autre. La consommer telle quelle ferait de la colonne une **élévation de privilège en libre-service** — il aurait suffi d'écrire « admin » dans Excel. Une liste blanche fixe, elle, serait soit trop large pour un enseignant, soit trop étroite pour un superAdmin. Le plafond exprime la seule règle qui tienne dans les deux cas : *on ne crée jamais plus permissif que soi*.

La hiérarchie n'est pas réinventée : `Role::permissivity()` et `Role::isMorePermissiveThan()` la portent depuis #121, et `Role::tryFromString()` porte la table d'alias FR/EN. Les dupliquer les aurait fait diverger au premier alias ajouté.

### Pourquoi refuser plutôt que rabattre sur le défaut

Rabattre « responsable » sur `etudiant` rendrait « N acceptées » sur un fichier que personne n'a compris — le défaut qu'on vient de corriger, sous un autre nom. Pour `statut`, le refus a de plus un effet mécanique : la colonne en base est un `ENUM`, et une valeur hors énumération serait rejetée par MySQL **pendant** l'exécution asynchrone, donc après écriture partielle.

### Pourquoi le rôle ne s'applique qu'à la création

CRITICAL-05 (#118) gèle le rôle LMS sur la mise à jour d'un compte existant. Sans la même règle ici, l'import deviendrait le chemin détourné qui promeut un compte déjà là : il suffirait de connaître une adresse pour la faire monter en grade par un fichier.

### Pourquoi relire le plafond à l'exécution

L'exécution est asynchrone et différée. Entre l'analyse et la confirmation, l'importateur a pu être rétrogradé ; rejouer le rôle stocké sans le revérifier rouvrirait **par le différé** ce que le plafond ferme à l'analyse. La ligne concernée bascule alors en erreur, avec son code, et les compteurs du rapport suivent — sinon l'utilisateur relirait « 1 acceptée » sur un import qui n'a rien créé.

### Pourquoi `checkdate` et non `createFromFormat`

`createFromFormat('d/m/Y', '31/02/2026')` rend le 3 mars sans rien signaler. Une date **inventée par le parseur** est crédible, donc jamais relue. La forme est reconnue par motif, puis validée par `checkdate`, qui refuse un jour qui n'existe pas. L'année est bornée à `[1900, 2100]` : une frappe où deux chiffres se perdent (`226`) est un cas courant, et aucune relecture humaine ne la rattrape.

### Pourquoi `syncWithoutDetaching` sur le pivot

Le code testait l'existence de l'inscription puis faisait `attach`, ce qui interdisait toute correction : renvoyer le fichier avec un statut corrigé ne changeait rien. Or « corriger trois lignes et renvoyer tout le fichier » est précisément le geste que #718 veut permettre. `syncWithoutDetaching` met à jour le pivot existant — l'import devient idempotent **et** correctif. Il ferme aussi une faille du test précédent : l'unique `(classe_id, user_id, annee_universitaire_id)` ne contraint rien quand l'année est `NULL`, MySQL n'égalant jamais `NULL`.

## Conséquences

- Un rapport d'analyse produit **avant** ce changement n'a pas ces trois clés dans son `payload`. La chaîne vide y ramène le comportement d'alors (étudiant, actif, daté du jour) : aucun import déjà analysé n'a besoin d'être rejoué.
- Le front n'a rien à changer : il proposait déjà les huit colonnes. Les nouveaux codes de refus (`role_interdit`, `role_plateforme`, `role_inconnu`, `statut_inconnu`, `date_invalide`) arrivent dans le rapport ligne à ligne, avec leur `message`, là où il affiche déjà `missing_name` et `duplicate`.
- `admin` n'a pas accès aux routes d'import (`role:enseignant,coordinateur,superAdmin`, `routes/api/lms.php:99-105`). Le plafond n'y change rien : il encadre le rôle **écrit**, pas celui qui ouvre la porte.

## Dette tracée

La clé de déduplication n'est **pas** normalisée en E.164, alors que #718 la déclare ainsi. `normalizePhone()` retire tous les non-chiffres, y compris le `+` : `+22670000000` et `0022670000000` produisent deux clés, donc deux comptes pour la même personne. Rejouer le **même** fichier reste idempotent, les chaînes étant identiques ; corriger le format d'un numéro et renvoyer le fichier ne l'est pas. Hors périmètre de cet ADR, qui traite les colonnes ; à reprendre dans #718.
