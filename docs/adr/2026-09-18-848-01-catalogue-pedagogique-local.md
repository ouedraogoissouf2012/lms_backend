# ADR-848-01 — La création locale du catalogue pédagogique

**Date :** 2026-09-18  
**Statut :** accepté  
**Issue :** #848 (backend) · bloque #845, #846, `frontend_lms#391`

## Décision

Une école autonome crée elle-même son catalogue pédagogique : **Classe**, **Matière**, et le lien **formateur ↔ matière**.

Le droit de le faire est exprimé par une **capacité**, `CatalogueAuthority::allowsLocalCatalogue()`, jamais par une comparaison de mode. Les appelants demandent un droit ; ils ignorent qu'un mode existe.

Cette capacité est produite par **`RosterAuthorityFactory`**, la fabrique existante. **Aucune seconde fabrique n'est créée.** Hors contexte d'établissement, aucune autorité locale n'est accordée.

Un établissement en mode `klassci` se voit **refuser** ces trois écritures.

Le schéma évolue au minimum : `matieres.klassci_id` devient nullable ; `matiere_enseignant` reçoit `matiere_id` et `enseignant_id` locaux, nullables. Un unique partiel `(institution_id, code)` est posé sur `classes`.

Chaque objet a **un seul service d'écriture**, sur le modèle d'[ADR-803-03](2026-09-15-803-03-trois-portes-un-service.md).

## Pourquoi

### Il ne manque pas un verrou, il manque un écrivain

Le seul créateur d'une `Classe` dans tout `app/` est `ClasseSyncService.php:238` — la synchronisation KLASSCI. Le seul créateur d'une `Matiere` est `ClasseMatieresSynchronizer`. Aucune route `POST` n'existe pour une matière, une classe, un enseignant ou un étudiant.

Donc, sans KLASSCI : jamais de classe, jamais de matière. L'import CSV crée pourtant de vrais comptes (`ImportApplyService.php:147`), mais son `enroll()` sort en silence quand la classe est nulle (`:165-170`) — il produit des utilisateurs qui n'appartiennent à rien.

**La table `classes` est déjà prête.** Seul `libelle` est NOT NULL sans valeur par défaut. #710 a levé le verrou `classes.klassci_id`, puis personne n'a écrit le créateur. C'est pourquoi la Classe est le premier pas et le moins cher.

La `Matiere` demande davantage : `matieres.klassci_id` est NOT NULL **sans défaut**, donc une migration précède l'écrivain. C'est le périmètre de #797, qui reste juste.

`matiere_enseignant` est le cas le plus lourd : la table n'a **aucune** colonne locale. Ce n'est pas une question de nullabilité — il faut créer `matiere_id` et `enseignant_id`.

### Nommer la capacité, jamais le mode

L'article 1 de l'épique #697 l'impose, et `RosterAuthority` en donne déjà la forme aboutie : une interface nommée par la préoccupation, deux implémentations, un point de liaison, zéro branchement sur le mode chez l'appelant.

On ne réutilise **pas** `RosterAuthority` pour autant. Son contrat est explicite — « Qui écrit la liste des apprenants ». Écrire le catalogue est une autre préoccupation : un établissement pourrait un jour tenir sa liste d'apprenants sans tenir son catalogue. Y ajouter `allowsLocalCatalogue()` violerait le principe de nommage que cette interface porte elle-même.

D'où une interface distincte, `CatalogueAuthority`, avec ses deux implémentations.

### Une seule fabrique, parce que la garde travaille par fichier

`.ocp-allowlist.json` exempte `RosterAuthorityFactory.php` comme **point-de-liaison**, et le fichier prévient : « Ajouter une entrée ici revient à déplacer la frontière d'architecture : cela exige une revue. » Il est couvert par `CODEOWNERS`.

Une seconde fabrique exigerait une seconde dérogation, donc un second endroit où le mode se résout — exactement ce que la première cherchait à éviter. La fabrique existante produit déjà « non pas le mode mais une CAPACITÉ » ; en produire deux ne la dénature pas, cela confirme sa raison d'être.

### Refuser au mode KLASSCI, sinon deux vérités concurrentes

C'est la cause racine de #673, payée une fois : deux sources écrivant la même réalité, et un miroir pour réconcilier après coup. Si un établissement branché à KLASSCI pouvait créer une classe locale, la synchronisation suivante n'aurait aucun moyen de savoir laquelle fait foi.

Le refus n'est donc pas une restriction commerciale, c'est la préservation d'une source unique par établissement.

### L'unique partiel sur `classes.code` n'est pas du confort

`ImportApplyService:165` retrouve une classe par `where('code', $code)->first()`. Or `classes.code` est **nullable et sans aucun index unique** : deux classes d'un même établissement peuvent partager un code, et `first()` en choisit une **en silence**. Un import inscrirait alors des apprenants dans la mauvaise classe, sans erreur.

Le défaut est latent aujourd'hui parce que les codes viennent de KLASSCI. Il devient atteignable dès qu'un humain les saisit. L'unique doit donc être posé **dans le même lot** que l'écrivain, pas après.

Partiel, car les lignes sans code doivent rester permises : sur MySQL 8, par colonne générée, selon le procédé déjà retenu en #541.

## Ce que cet ADR ne décide pas

**Le code d'inscription.** Il est déjà tranché par [ADR-803-03](2026-09-15-803-03-trois-portes-un-service.md), accepté : il appartient à la `Classe`, il est court, insensible à la casse, exclut les glyphes qu'on confond en dictant, et il est révocable. Il reste à implémenter — c'est #846 — et il ne doit **pas** être confondu avec `classes.code`, qui désigne la classe et non l'inscription. Deux colonnes, deux rôles.

**L'écriture de `user_classes`.** ADR-803-03 l'a réservée à son unique écrivain légitime, alimenté par KLASSCI. Rien ici ne la touche.

**Le rendu d'une copie par un étudiant local.** `evaluation_submissions.klassci_etudiant_id` est NOT NULL et `EvaluationStudentAttemptController:96` y écrit `$user->klassci_id`, nul en local. C'est #798, distinct.

**Le rattachement d'une séance locale à une classe locale.** `seances` ne porte que des colonnes `klassci_*` — aucune clé locale, contrairement à `lessons`. Le travail d'autonomie a été fait pour les leçons et oublié pour les séances. Décision séparée.

**Les écrans.** Côté front, aucune ligne avant que ces routes existent.

## Conséquences

Un établissement autonome obtient un catalogue, donc des classes où inscrire les comptes que l'import sait déjà créer. C'est ce qui rend #845 et #846 visibles pour un utilisateur : publier une période qui commande des classes réelles, et distribuer un code vers une classe qui existe.

La garde OCP gagne une seconde capacité sans gagner un second point de résolution.

Le risque assumé est celui de tout élargissement d'écriture : trois nouveaux chemins de création dans un produit multi-établissements. Chacun devra être gardé par `institution_id` **et** par la capacité, et testé avec un vrai jeton — `Sanctum::actingAs()` n'émettant pas de jeton, aucun tenant n'est résolu et un test d'isolation écrit ainsi ne prouve rien.

## Vérifié

`origin/lms` @ `b7d1c53c`, schéma lu directement en base, le 2026-09-18.
