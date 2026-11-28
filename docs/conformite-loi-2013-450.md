# Conformité à la loi ivoirienne n° 2013-450 — note pour la déclaration ARTCI

> #1705. Cette note décrit ce que le LMS **fait réellement**, pour préparer la déclaration préalable à l'ARTCI
> (article 5). Les durées ci-dessous sont lues dans `config/conservation.php`, la source unique. La garde
> `tests/Feature/Conservation/DureesDeConservationTest.php` échoue si cette note oublie un usage ou une durée de ce
> tableau.

## 1. Le traitement

- **Responsable du traitement** : chaque établissement scolaire qui utilise le LMS, pour ses propres élèves,
  enseignants et personnels. Le LMS est un produit 100 % en ligne.
- **Finalités** : l'enseignement à distance et en présentiel :
  - les cours, leçons et chapitres ;
  - les évaluations et leurs notes ;
  - les séances, en salle et en visio, et les présences ;
  - le forum et les notifications de la classe.
- **Personnes concernées** : les élèves, les enseignants, les coordinateurs et les administrateurs d'un établissement.
- **Données** :
  - l'identité et le contact du compte (nom, adresse électronique) ;
  - l'appartenance aux classes ;
  - les copies et les notes ;
  - les présences ;
  - la progression dans les leçons et les quiz ;
  - les messages et les fichiers déposés ;
  - les enregistrements des séances en visio ;
  - les preuves de consentement ;
  - le journal d'audit des actions.

## 2. Les durées de conservation

| Usage | Durée | Depuis | Appliquée par |
|---|---|---|---|
| Comptes supprimés | 30 jours | la suppression du compte | purge planifiée chaque nuit (`comptes:purge --apply`) |
| Établissements supprimés | 30 jours | la suppression de l'établissement | purge **manuelle** après simulation (`purge:run institutions`) |
| Journal d'audit | 365 jours | l'écriture de l'entrée | purge planifiée chaque nuit (`audit:purge`) |
| Enregistrements des séances en visio | 365 jours | la fin de l'enregistrement | purge planifiée chaque nuit (`recordings:purge --apply`) |
| Chapitres mis à la corbeille | 30 jours | la mise à la corbeille | purge planifiée chaque nuit (`chapters:purge --apply`) |
| Preuves de consentement | à fixer (#1827) | le recueil du consentement | aucune purge à ce jour (voir ci-dessous) |
| Copies et notes d'évaluation | celle du compte | la purge du compte de l'élève | la purge du compte (cascade) |
| Présences aux séances | celle du compte | la purge du compte de l'élève | la purge du compte (cascade) |
| Progression dans les leçons et les quiz | celle du compte | la purge du compte de l'élève | la purge du compte (cascade) |
| Messages du forum, notifications et fichiers déposés | celle du compte | la purge du compte | la purge du compte (cascade) |

**La purge d'un établissement reste manuelle**, par décision. Elle détruit une école entière, avec toutes ses
données ; une telle destruction se déclenche à la main, après lecture d'une simulation (`purge:run institutions`),
et jamais parce qu'une horloge a sonné.

**Les preuves de consentement** sont conservées sans modification ni suppression (#716). Leur durée propre reste à fixer
(#1827). D'ici là :

- un compte supprimé qui porte une telle preuve, comme personne concernée ou comme auteur, est **épargné** par la purge
  des comptes ;
- la raison est consignée au journal de l'application ;
- les autres comptes sont purgés normalement.

## 3. Le consentement

- **Visio** : l'enregistrement d'une séance en visio demande le consentement de la personne enregistrée. Il est
  consigné avec sa date, son auteur et sa preuve, et peut être retiré.
- **Retrait** : un retrait n'efface pas la preuve du consentement passé.

## 4. Les droits des personnes

- **Accès et export** : l'administrateur de l'établissement exporte, à la demande d'un élève, toutes les données que le
  LMS détient sur lui, en JSON et en PDF. Chaque export est journalisé. *(À venir : #1705, PR 2.)*
- **Effacement** : la suppression d'un compte est d'abord réversible. Le compte est définitivement détruit au terme du
  délai ci-dessus, avec les données qui le suivent.

## 5. La sécurité

- **Isolation par établissement** : chaque ligne porte son établissement. Une donnée d'un autre établissement est
  introuvable, avec la même réponse qu'une donnée absente.
- **Accès** : uniquement par jeton d'authentification. Chaque route est réservée aux rôles qui en ont besoin.
- **Journal d'audit** : les actions sensibles y sont tracées, y compris chaque destruction définitive, qui est
  consignée avant d'avoir lieu.
- **Fichiers** : les documents déposés et les vidéos des cours sont stockés hors de l'espace public. Ils ne sont servis
  qu'à un compte autorisé, ou par une adresse signée et limitée dans le temps. Une seule exception, délibérée : les
  diapositives des chapitres, des rendus d'images destinés à la diffusion en classe. Elles ne contiennent aucune
  donnée d'élève (`ChapterArtifactStorage`).
