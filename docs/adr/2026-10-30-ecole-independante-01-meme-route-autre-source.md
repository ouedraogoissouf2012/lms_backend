# ADR-EI-01 — Même route, autre source : les lectures d'une école créée depuis le LMS

**Date :** 2026-10-30  
**Statut :** accepté (lot 1 ; lot 2, les classes, ajouté le 2026-11-09 ; les évaluations, E1, ajouté le 2026-11-12)  
**Issue :** aucune encore · prolonge [ADR-848-01](2026-09-18-848-01-catalogue-pedagogique-local.md),
[ADR-805-01](2026-09-19-805-01-resolution-des-identifiants.md) et
[ADR-760-01](2026-09-19-760-01-deux-portes-deux-espaces.md)

## Le constat

Un test de bout en bout d'une école créée depuis le LMS, par les chemins du produit, a montré sept écrans
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
2. **La liaison se fait par `bind()`, jamais par `singleton()`**, dans le fournisseur du domaine du port :
   `CatalogueServiceProvider` pour le catalogue, `EvaluationServiceProvider` pour les évaluations (amendé par D72, E1).
   C'est la même raison que pour `CatalogueAuthority` : un singleton rejouerait le premier tenant pour tous les suivants.
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

Une école créée depuis le LMS n'a qu'un espace : `klassci_id` y est NULL partout. La source locale rend donc l'id local, et
c'est celui que le front renvoie. Les écritures l'acceptent déjà (`ResolvesMirroredIdentifier::localIdFor`, avec
précédence au local).

Ce n'est pas l'arbitrage à l'entrée qu'interdit ADR-760-01. Rien n'est deviné : l'implémentation est choisie par le
mode déclaré. Et la prémisse de cet ADR, « une ressource désignée dans deux espaces », ne vaut pas dans un établissement
qui n'en a qu'un.

**ADR-760-01 est amendé par le lot 2** (amendement EI-02, 2026-11-09) : dans une école créée depuis le LMS, ses deux
portes servent la même fiche locale, et son 409 ne vaut plus que dans une école créée depuis KLASSCI.

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
  comme avant. Locale : `null`, et le front recharge ou revient en arrière. Sans cela, une école créée depuis KLASSCI
  puis basculée en mode autonome, dont les matières gardent leur `klassci_id`, enverrait un id KLASSCI à une page qui lit l'id local, et
  ouvrirait une autre matière en 200. Naviguer par l'id local passera par une clé neutre : c'est une suite déclarée.
- **Les compteurs inconnus valent `null`, pas 0.** Une séance locale ne porte que le nom de sa matière, en texte.
  Les évaluations valent 0, ce qui est un fait.
- **Les évaluations rendues vides (`[]`, 0) sont PROVISOIRES.** L'utilisateur a décidé que l'école créée depuis le LMS aura
  ses évaluations (D72). Le lot qui les construit reprendra ces sources locales, page matière comme fiche de classe,
  pour y brancher les évaluations locales.

## Deuxième cas appliqué (lot 2, 2026-11-09)

| Route | Port | Source KLASSCI | Source locale |
|---|---|---|---|
| `GET /lms/teacher/classes` | `TeacherClassesReader` | `TeacherClassesQueryService` | `LocalTeacherClassesReader` |
| `GET /lms/classes/{id}` | `ClasseDetailsReader` | `ClasseDetailsQueryService` | `LocalClasseDetailsReader` |
| `GET /lms/classes/local/{id}` | `ClasseLocalDoorReader` | `ClasseLocalDetailsService` | `LocalClasseDetailsReader` |
| `GET /lms/classes/{id}/etudiants` | `ClasseEtudiantsReader` | `ClasseEtudiantsQueryService` | `LocalClasseDetailsReader` |

Dans la source locale :
- **« Mes classes »** : les classes où l'appelant est désigné formateur d'au moins une matière, triées par libellé.
  `effectif_actuel` compte les inscriptions actives ; `effectif` vaut `null`, la colonne n'étant tenue que par le
  synchroniseur KLASSCI.
- **La fiche et le roster** : UNE classe sert les trois lectures, pour qu'une seule grille d'accès existe. La gestion
  lit toute classe de l'établissement, un enseignant une classe où il est désigné, un apprenant une classe où son
  inscription est active, sans la liste des élèves (GHSA-gg7j). Hors de ces cas, 404.
- **Ce qu'aucune table locale ne porte vaut `null`** (capacité, séances de la semaine, taux de remplissage) ; l'emploi
  du temps et les évaluations programmées valent `[]`, les secondes provisoirement (D72).

ADR-760-01 est amendé en conséquence (amendement EI-02).

Ce que le lot 2 change AUSSI dans l'école créée depuis KLASSCI, voulu et déclaré :
- **La fiche ne rend plus 500 pour un échec de KLASSCI** (le défaut de `fetchClasse`, qui rattrapait toute erreur).
  Ses deux portes passent par `RendersKlassciBackedErrors`, comme le roster : 4xx relayé, 401
  `klassci_session_expired`, 503 avec `Retry-After`.
- **Une panne interne est rapportée, jamais écrite à la main** (#948). Une panne de base, `RuntimeException`, passe
  par la branche 500 du trait, qui la rapporte une fois sans le message ni les valeurs de la requête. Le filet des
  autres erreurs (porte historique, `/etudiants`) fait `report()` au lieu d'écrire message et trace : son corps ne
  change pas.
- **Des tests existants changent**, sur décision : leurs cas « école créée depuis le LMS » passent à la source
  locale ; leurs cas « école créée depuis KLASSCI » restent inchangés.

**Dette FERMÉE par le lot 3a : l'école basculée.** Une école créée depuis KLASSCI puis passée en mode autonome
(route du supradmin, `InstitutionController`) suit son mode : ses portes lisent l'id LOCAL. Tracée au lot 2, telle
qu'elle était :
- **Cause** : ses classes gardent leur `klassci_id`, et la liste d'administration (`/proxy/classes`, lue par
  `useAdminClasses`) comme le référentiel que « Mes classes » fusionne par `id` restent dans l'espace KLASSCI.
- **Effet exact** :
  - un identifiant KLASSCI ouvert par la porte historique est lu comme local : la fiche d'une autre classe de la
    même école, en 200, ou un 404 ;
  - une carte de « Mes classes » peut porter la mesure d'une autre classe.
  - Aucune fuite entre écoles : tout reste borné à l'établissement.
- **Ce qui l'a fermée** : le lot 3a. `/proxy/classes` suit le mode, par le port `InstitutionClassesReader` : la liste
  d'administration et le référentiel de « Mes classes » donnent l'id LOCAL, celui des portes de la fiche. Le test
  `ClassesEtablissementTest::test_dans_une_ecole_basculee_la_liste_et_la_fiche_parlent_le_meme_espace` le prouve.
- Jusqu'à la fusion du lot 3a, la contention reste MANUELLE : `changeMode` (`InstitutionCrudService`) ne refuse pas
  la bascule.

**Écart connu, échec fermé** : la grille d'accès lit le rôle par `isManager()` / `isTeacher()`, en correspondance
exacte, alors que la garde de rôle des routes ignore la casse et admet l'alias `administrator`. L'alignement va au
lot de #949.

## Troisième cas appliqué (lot 3a, 2026-11-09)

| Route | Port | Source KLASSCI | Source locale |
|---|---|---|---|
| `GET /proxy/classes` | `InstitutionClassesReader` | `KlassciInstitutionClassesReader` (l'appel `getClasses` d'avant) | `LocalInstitutionClassesReader` |

- La grille d'accès de la fiche est extraite dans `ClassesAtteintes`. C'est une seule règle pour la fiche et la
  liste, et l'effectif est rendu, jamais la liste des élèves.
- `is_active` vient de la Période : `TrainingSession::estActive()`, publiée et pas terminée (D124) ; `null` sans
  Période, ou quand la Période est supprimée.
- **Changement aussi dans l'école créée depuis KLASSCI, voulu et déclaré** : la branche « défaillance interne » du
  rendu des proxys (`RendersKlassciProxyErrors`) rapporte désormais la panne, une fois (#948). C'est valable pour
  toutes les routes `/proxy` ; leur rendu ne change pas. Les deux tableaux de bord (`ProxyDashboardController`)
  journalisaient aussi l'exception avant de la rendre (`CaughtExceptionLog`, #1008) : la panne aurait été écrite
  deux fois. Ce sont les deux seuls des 16 sites de `proxyErrorResponse` à le faire ; leur écriture est retirée.

## Quatrième cas appliqué (lot 3c, 2026-11-09)

| Route | Port | Source KLASSCI | Source locale |
|---|---|---|---|
| `GET /proxy/matieres` | `InstitutionMatieresReader::list` | `KlassciInstitutionMatieresReader` (l'appel `getMatieres` d'avant) | `LocalInstitutionMatieresReader` |
| `GET /admin/matieres` | `InstitutionMatieresReader::listForAdmin` | `KlassciInstitutionMatieresReader` (`AdminMatieresQueryService`, inchangé) | `LocalInstitutionMatieresReader` |
| `GET /proxy/structure` | `InstitutionStructureReader` | `KlassciInstitutionStructureReader` (l'appel `getStructure` d'avant) | `LocalInstitutionStructureReader` : deux listes vides |

- **Le jeton KLASSCI personnel est exigé par la source KLASSCI**, et non plus par les contrôleurs : vérifié avant le
  choix de source, il refusait l'école créée depuis le LMS. La réponse d'un jeton absent ne change pas (401
  `klassci_token_missing`).
- **Sur `/admin/matieres`, la garde de rôle du contrôleur passe désormais avant le jeton.** Le middleware
  `role:admin,coordinateur` n'admet que des comptes que `isManager()` accepte : l'ordre ne change aucun refus
  atteignable.
- La grille des matières est `MatieresAtteintes`. Elle réutilise `ClassesAtteintes`, et y ajoute, pour l'enseignant, la
  désignation sur CETTE matière. Les règles d'affichage de l'écran d'administration (couleur par défaut, `N/A`, totaux)
  sont écrites une fois, dans `AdminMatierePresentation`, pour les deux sources.
- Une défaillance interne de `/admin/matieres` reste écrite une fois par `CaughtExceptionLog`, sans les valeurs du
  SQL (#1008) ; les tests du lot la fixent pour les deux sources. Le corps du 500 ne change pas. Un refus 4xx de
  KLASSCI y rend toujours 500 : c'est préexistant, et hors de ce lot.

## Cinquième cas appliqué (lot 3d, 2026-11-12)

| Route | Port | Source KLASSCI | Source locale |
|---|---|---|---|
| `GET /lms/seances/upcoming` | `SeancesListReader::getUpcomingForUser` | `SeancesListQueryService`, inchangé | `LocalSeancesListReader` |
| `GET /lms/seances/my-teaching` | `SeancesListReader::getMyTeachingForUser` | idem | idem |
| `GET /lms/seances/my-classes` | `SeancesListReader::getMyClassesForUser` | idem | idem |

- **Le jeton KLASSCI est exigé par la source KLASSCI seule.** Il l'était avant tout le reste, y compris pour le chemin
  du gestionnaire, pourtant local : l'école créée depuis le LMS recevait partout le 401 `klassci_token_missing`.
- **La grille (D119)** : la gestion lit toutes les séances de l'école ; un enseignant, les siennes (`created_by`, la
  règle de l'historique), car une séance locale n'a pas de colonne « enseignant » ; un apprenant, et tout rôle non
  reconnu, celles de ses classes à inscription active, moins celles qu'il a masquées. La règle de l'inscription active
  est extraite dans `ClassesAtteintes::inscrites`, écrite une fois.
- **La forme du calendrier est écrite une fois** (`SeanceCalendarProjection`), pour le chemin du gestionnaire de l'école
  créée depuis KLASSCI et pour la source locale. Seuls les ids diffèrent, par `SeanceIdentite` : ids KLASSCI du miroir
  d'un côté, ids LOCAUX de l'autre (la séance, la classe de `/proxy/classes`, le compte auteur ; `matiere.id` vaut
  `null`, une séance locale ne porte que le nom de sa matière).
- **La fin d'une séance locale est SUPPOSÉE** : 2 h après son début, une constante nommée en un lieu. La table ne tient
  aucune heure de fin, et le calendrier en exige une. Le CLIENT le lit sur la ligne : `heure_fin_estimee` vaut `true`,
  à la racine et dans `programmation`, pour toute fin posée par la projection (y compris le chemin de la gestion d'une
  école créée depuis KLASSCI), et `false` pour une fin rendue par KLASSCI. Une déclaration que le client ne lit pas ne
  suffisait pas (décision de la superviseure, relevé de Fille 2).
- **L'école créée depuis KLASSCI ne change pas** : sa source est le service d'avant, le chemin du gestionnaire garde son
  exclusion des séances locales et ses ids KLASSCI. Le contrôleur ne reçoit plus la source par son constructeur, mais
  par sa méthode ; le journal des pannes (`CaughtExceptionLog`), sans état et sans école, y reste.
- **La classe d'une ligne locale** est celle que désigne `classe_id` : son libellé et ses inscriptions actives, lus en
  une requête bornée, jamais le `classe_nom` libre de la séance (revue indépendante du lot).
- **Limite déclarée, école basculée** : une séance miroir de KLASSCI n'a ni auteur ni classe locaux. Seule la gestion la
  lit, avec le nom d'enseignant qu'elle porte ; ni l'enseignant ni l'élève ne la lisent, faute de lien local.
- **Hors de ce lot, dans la file** : les actions du calendrier sur une séance locale (`/details`, le basculement de la
  visio) exigent encore le jeton. L'activation de la visio suit (« La visio d'une séance », plus bas).
## Cas appliqué : les enseignants (lot 3b, 2026-11-12)

| Route | Port | Source KLASSCI | Source locale |
|---|---|---|---|
| `GET /proxy/enseignants` | `InstitutionTeachersReader::annuaire` | `KlassciInstitutionTeachersReader` (la logique d'avant, repli local compris) | `LocalInstitutionTeachersReader` |
| `GET /lms/enseignants` | `InstitutionTeachersReader::listeEnrichie` | `KlassciInstitutionTeachersReader` (liste enrichie, cache, `meta`) | `LocalInstitutionTeachersReader` |

- **Une seule règle de visibilité**, celle de #1009 : `TeacherDirectoryVisibility` (D120). La gestion lit tous les
  enseignants, un enseignant sa ligne, tout autre rôle aucune. Elle DESCEND dans chaque source. La source KLASSCI
  l'applique après le cache de KLASSCI (`envelopeFor`), la source locale à sa requête (`restrictLocal`, seule règle
  locale, sans copie). Les contrôleurs gardent le seul court-circuit : un lecteur qui ne verrait aucune ligne ne
  déclenche aucune lecture.
- **Les ids de la source locale sont LOCAUX** (`users.id`). Aucune clé `klassci_*`, et aucun id KLASSCI n'est rendu,
  même pour le compte d'une école basculée qui garde le sien (D132 Q3). Le repli local de l'école créée depuis
  KLASSCI (`LocalTeacherDirectory`, quand KLASSCI rend 0) mêle encore les deux espaces : relevé à part, hors du lot.
- **Les détails** : les matières où l'enseignant est désigné, avec leurs classes, et ses classes. C'est la règle de
  `ClassesEnseignees` (désignation sur `classe_matiere`, classe et matière de l'établissement), lue en une requête
  pour toute la liste. Ce que le LMS ne tient pas vaut `null` (heures, taux, séances, filière, niveau), et aucun bloc
  `statistiques` n'est rendu (D120).
- **L'école créée depuis KLASSCI ne change pas** : sa logique est déplacée des contrôleurs vers sa source, avec les
  mêmes appels, le même repli, le même cache et le même `meta`. Ses tests existants, et ceux de #1009, passent sans
  modification.

## Conséquences

- **Lot 1 : l'école créée depuis KLASSCI ne change pas dans ce qu'elle rend.** Il est prouvé par ses tests existants, lancés sans
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
  `my-matieres` dans les deux volets. Une école créée depuis le LMS n'atteint plus ce point de rendu : les deux méthodes de
  cette route passent sur le volet KLASSCI seul. Les autres sites gardent leurs deux volets. En échange,
  `MatieresEcoleIndependanteTest` vérifie plus strictement le volet indépendant : un compte porteur d'un jeton n'émet
  AUCUN appel KLASSCI. Les lots suivants appliqueront la même règle, site par site.
- **Un écart est consigné, pas corrigé ici** : la source KLASSCI de la page matière rend encore à un enseignant les
  leçons de ses collègues. L'aligner relève du lot qui borne la lecture des leçons.
- **Le contrat front/serveur** est écrit dans `docs/openapi.yaml`, avec le fichier front qui lit chaque clé.
  `MatieresContractTest` le vérifie pour les deux sources.
- Les lots suivants (classes de l'enseignant, catalogue d'administration, séances, tableaux de bord) suivent cette
  forme. Ils ajoutent des ports, jamais une seconde comparaison au mode.

## Les évaluations (D72, E1, 2026-11-12)

L'utilisateur a décidé que l'école créée depuis le LMS a ses évaluations (D72). E1 couvre la création et la propriété.
L'élève (E2), la correction (E3) et les pages qui comptent les évaluations (E4) suivent.

| Route | Port | Source KLASSCI | Source locale |
|---|---|---|---|
| `POST /evaluations` | `EvaluationProgramming` | `KlassciEvaluationProgramming` : mise en ligne d'une évaluation programmée dans KLASSCI | `LocalEvaluationProgramming` : programmation dans le LMS, sur une classe et une matière locales |

- **Un fait du modèle, pas une source.** `evaluations` porte trois colonnes locales :
  - `classe_id` et `matiere_id`, remplies par la source locale ;
  - `enseignant_id`, l'auteur, écrit par les deux sources.
  La propriété accepte `enseignant_id`, ou, pour une ligne d'avant D72, `klassci_enseignant_id`. Elle ne compare
  jamais le mode.
- **Deux familles d'identifiants, jamais mêlées (D132).** Un id local ne vit jamais sous un nom `klassci_*`, même dans
  une requête. Chaque source refuse la famille de l'autre (422 `identifier_out_of_scope`).
- **L'auteur est le formateur désigné du couple (D136),** même quand la gestion crée. La gestion n'en est l'autrice
  que si personne n'est désigné. Elle garde ses droits par son rôle, et l'acteur réel reste au journal d'audit.
- **Ce qui change AUSSI dans l'école créée depuis KLASSCI, voulu et déclaré :**
  - sans `klassci_evaluation_id`, la création est refusée (422 `klassci_scheduling_required`). Les deux appelants
    du front envoient toujours la programmation ;
  - le 403 d'un compte sans identité KLASSCI porte `reason: klassci_teacher_identity_missing`, avec le même message ;
  - `enseignant_id` est écrit ;
  - les deux libellés sont lus séparément, chacun en fail-soft, et un libellé vide est sauté. Les ids sont typés en
    entiers ;
  - ces deux lectures partent avant le garde du 409 : un doublon coûte deux appels KLASSCI avant de recevoir son 409.
- **Dette déclarée** : `TeacherOwnershipScope::applyToEvaluations`, dans la recherche globale, ne lit encore que
  `klassci_enseignant_id`. Dans une école créée depuis le LMS, la recherche ne rend donc aucune évaluation à son
  auteur. Elle ferme, et rien ne fuit. L'alignement vient avec la PR « listForTeacher » (D136).

## La visio d'une séance (2026-11-12, #926, Refs #917, #929)

| Route | Port | Source KLASSCI | Source locale |
|---|---|---|---|
| `POST /lms/seances/{seanceId}/activate-visio` | `VisioActivationSource` | `VisioActivationService`, inchangé | `LocalVisioActivation` |
| `POST /lms/seances/{seanceId}/toggle-visio` | `VisioToggleSource` | `VisioToggleService`, inchangé | `LocalVisioToggle` (délègue à `LocalVisioActivation` et à `VisioDeactivation`) |

- **L'identifiant suit le mode, comme le calendrier du lot 3d** : identifiant KLASSCI dans une école en mode KLASSCI,
  id LOCAL dans une école en mode autonome. La séance locale est cherchée dans l'établissement du compte, sans jeton ni
  appel KLASSCI, et rien n'est écrit sous un nom `klassci_*` (D132). Un salon déjà attribué est gardé (`??=`).
- **Qui active** : qui gère la séance, `VisioActorAuthorization::canManage` (son auteur `created_by`, ou le personnel de
  gestion de l'établissement). Le refus a une seule forme, `managementRefusal` : 403 `actor_not_authorized`.
- **L'admission des élèves n'est pas une source, c'est un fait de la séance** : elle déclare sa classe, locale
  (`classe_id`) ou KLASSCI (`klassci_classe_id`). `LocalClasseAudienceSource` lit la classe locale d'abord, comme
  l'accès aux enregistrements, bornée à l'établissement, et l'inscription par `EnrollmentSource` (ADR-803-03). Une
  séance miroitée suit son chemin d'avant. Le port gagne `designatesClasse`.
- **Ce qui change AUSSI dans l'école en mode KLASSCI, voulu et déclaré** : une séance créée dans le LMS qui porte sa
  classe locale admet désormais les élèves de cette classe. Elle était refusée en `no_classe_id`.
- **Les élèves sont avertis (élargit #929, décision de l'utilisateur)** : l'activation et le démarrage d'une séance
  sans classe KLASSCI avertissent les élèves de la même audience que l'admission (`ClasseAudienceSource::studentsOf`),
  lue sur la séance désignée par son id local (`seance_locale_id`) ; sans cet id, rien n'est deviné. Dette : le
  composite d'inscriptions ne liste pas les élèves d'une classe, `studentsOf` lit le pivot de sa jambe locale.
- **Reste ouvert** : le basculement de la visio (`toggle-visio`) garde son 401 sans jeton ; la liste des participants et
  le rattachement d'un enregistrement d'une séance locale (#929, « hors périmètre ») ; le côté enseignant de #917
  (« Mes classes », `classes_concernees`).
