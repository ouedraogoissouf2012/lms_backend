# ADR-EI-01 — Même route, autre source : les lectures d'une école indépendante

**Date :** 2026-10-30  
**Statut :** accepté (ARRÊT 2 validé, lot 1)  
**Issue :** aucune encore · prolonge [ADR-848-01](2026-09-18-848-01-catalogue-pedagogique-local.md),
[ADR-805-01](2026-09-19-805-01-resolution-des-identifiants.md) et
[ADR-760-01](2026-09-19-760-01-deux-portes-deux-espaces.md)

## Le constat

Un test de bout en bout d'une école indépendante, créée par les chemins du produit, a montré sept écrans
d'administration sur treize inutilisables, et un enseignant incapable de créer une leçon depuis l'écran.

La cause est la même partout. Chaque route cassée ne connaît qu'UNE source, KLASSCI :
- **401 `klassci_token_missing`** quand la route exige le jeton personnel ;
- **503** quand elle appelle KLASSCI sans URL ;
- **une liste vide** quand son repli local écarte les lignes sans `klassci_id`.

Les données locales existent pourtant (classes, `classe_matiere`, leçons, séances locales). Seule leur LECTURE
manque, derrière les routes que le front appelle déjà.

## Décision

**La route ne change pas, seule la source change.** Chaque lecture concernée devient un **port** : une interface
nommée par ce qu'elle rend, jamais par le mode, avec deux implémentations.

| Rôle | Implémentation |
|---|---|
| Source KLASSCI | le service existant, à sa place et sous son nom, qui déclare seulement l'interface |
| Source locale | une classe neuve qui lit les tables locales et rend les clés que le front lit |

Trois règles de câblage :

1. **Le choix se fait au point de liaison.** `RosterAuthorityFactory::sourceForCurrentTenant($klassci, $local)` est le
   seul endroit qui compare le mode. Il n'y a qu'UNE méthode générique, et pas une par port : chaque méthode
   recopierait la comparaison, et ce fichier existe pour qu'elle n'ait lieu qu'une fois. La liste d'exemption OCP ne
   change pas. Sans établissement, la source est KLASSCI, comme avant ce choix.
2. **La liaison se fait par `bind()`, jamais par `singleton()`**, dans `CatalogueServiceProvider`. C'est la même raison
   que pour `CatalogueAuthority` : un singleton rejouerait le premier tenant pour tous les suivants.
3. **Le contrôleur reçoit le port par sa MÉTHODE, jamais par son constructeur.** Laravel garde l'instance du contrôleur
   sur l'objet `Route`, et seul Octane la vide. Une source reçue à la construction resterait celle de la première école
   servie, sous Octane comme dans une suite de tests. `MatieresEcoleIndependanteTest` le prouve : deux écoles appelées
   dans la même méthode, et le test rougit si l'injection remonte au constructeur.

## Pourquoi pas des routes locales choisies par le front

Le client ne connaît pas le mode et ne doit pas le connaître (#805). Un test interdit « standalone » et « mode » dans
la réponse de login. Des routes séparées obligeraient chaque écran à choisir la sienne, donc à recevoir une capacité
par écran. Le travail du front doublerait, et la décision passerait chez le client. Avec la même route, un écran marche
dans les deux volets sans aucune branche.

## L'espace d'identifiants

Une école indépendante n'a qu'un espace : `klassci_id` y est NULL partout. La source locale rend donc l'id local, et
c'est celui que le front renvoie. Les écritures l'acceptent déjà (`ResolvesMirroredIdentifier::localIdFor`, avec
précédence au local).

Ce n'est pas l'arbitrage à l'entrée qu'interdit ADR-760-01. Rien n'est deviné : l'implémentation est choisie par le
mode déclaré. Et la prémisse de cet ADR, « une ressource désignée dans deux espaces », ne vaut pas dans un établissement
qui n'en a qu'un.

**ADR-760-01 n'est pas modifié par ce lot.** Sa porte locale répond encore 409 à une classe sans `klassci_id`. Le lot
qui sert le détail d'une classe locale le rouvrira explicitement.

## Premier cas appliqué (lot 1)

| Route | Port | Source KLASSCI | Source locale |
|---|---|---|---|
| `GET /lms/teacher/my-matieres` | `TeacherMatieresReader` | `MyMatieresQueryService` | `LocalTeacherMatieresReader` |
| `GET /lms/matieres/{id}` | `MatiereDetailsReader` | `MatiereDetailsQueryService` | `LocalMatiereDetailsReader` |

Dans la source locale :
- **« Mes matières »** : les matières où l'appelant est désigné formateur (`classe_matiere.enseignant_id`), comme la
  source KLASSCI, qui interroge `me/teacher-dashboard` avec le jeton personnel. `LocalClasseMatiereLinker` ne désigne
  qu'un compte de rôle enseignant : un coordinateur ou un superAdmin, que la route admet, reçoit donc toujours une
  liste vide. Les compteurs de la carte ne portent que sur les leçons de l'appelant, comme sa page.
- **La page d'une matière** :
  - la gestion lit toute la matière ;
  - un enseignant, la matière où il est désigné, et seulement SES leçons (décision de l'utilisateur, 2026-10-31) ;
  - un apprenant, la matière d'une classe où son inscription est active, et les leçons publiées de ces classes.
    Une leçon sans classe ne lui est pas montrée : c'est la règle de `LessonReadScope` (#941) partout ailleurs.
  - Hors de ces cas, 404.
- **L'identifiant de navigation vers la page matière** (`matiere_klassci_id` sur une leçon) suit la même source, par
  un troisième port, `MatierePageNavigation`, résolu à l'appel dans `LessonListService`. KLASSCI : le `klassci_id`,
  comme avant. Locale : `null`, et le front recharge ou revient en arrière. Sans cela, une école passée de KLASSCI à
  indépendante, dont les matières gardent leur `klassci_id`, enverrait un id KLASSCI à une page qui lit l'id local, et
  ouvrirait une autre matière en 200. Naviguer par l'id local passera par une clé neutre : c'est une suite déclarée.
- **Les compteurs inconnus valent `null`, pas 0.** Une séance locale ne porte que le nom de sa matière, en texte.
  Les évaluations valent 0, ce qui est un fait.
- **Les évaluations rendues vides (`[]`, 0) sont PROVISOIRES.** L'utilisateur a décidé que l'école indépendante aura
  ses évaluations (D72). Le lot qui les construit reprendra ces sources locales, page matière comme fiche de classe,
  pour y brancher les évaluations locales.

## Conséquences

- **Le volet KLASSCI ne change pas dans ce qu'il rend.** Il est prouvé par ses tests existants, lancés sans
  modification. Code partagé touché :
  - `MatiereLessonsAndStatsBuilder` : la lecture des leçons par id local devient une méthode publique, que le chemin
    KLASSCI appelle après sa traduction ;
  - `LessonListService` : l'id de navigation passe par `MatierePageNavigation`, dont la source KLASSCI reprend telle
    quelle l'ancienne méthode privée.
- **Deux changements touchent AUSSI le volet KLASSCI, voulus et déclarés** (revue indépendante du lot 1) :
  - `lms/matieres/{matiereId}` n'accepte plus qu'un id numérique (`whereNumber`). Un segment non numérique levait un
    TypeError rendu en 500 ; c'est désormais un 404 ;
  - une `QueryException` est une `RuntimeException` : elle tombait dans le rendu des erreurs KLASSCI, sans être
    JOURNALISÉE. Elle prend désormais la branche interne, journalisée, dans les deux méthodes du contrôleur.
- **Un test existant change, et c'est déclaré.** `KlassciTokenMissingReasonTest` (#806) jouait le 401 de
  `my-matieres` dans les deux volets. Une école indépendante n'atteint plus ce point de rendu : les deux méthodes de
  cette route passent sur le volet KLASSCI seul. Les autres sites gardent leurs deux volets. En échange,
  `MatieresEcoleIndependanteTest` vérifie plus strictement le volet indépendant : un compte porteur d'un jeton n'émet
  AUCUN appel KLASSCI. Les lots suivants appliqueront la même règle, site par site.
- **Un écart est consigné, pas corrigé ici** : la source KLASSCI de la page matière rend encore à un enseignant les
  leçons de ses collègues. L'aligner relève du lot qui borne la lecture des leçons.
- **Le contrat front/serveur** est écrit dans `docs/openapi.yaml`, avec le fichier front qui lit chaque clé.
  `MatieresContractTest` le vérifie pour les deux sources.
- Les lots suivants (classes de l'enseignant, catalogue d'administration, séances, tableaux de bord) suivent cette
  forme. Ils ajoutent des ports, jamais une seconde comparaison au mode.
