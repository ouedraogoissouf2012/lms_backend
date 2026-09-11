# Parcours enseignant — tout ce qui a cassé, tout ce qui a été réparé

> Période couverte : **28 août → 9 septembre 2026**.
> Reconstitué à partir du journal complet de la session (19 388 lignes), de l'historique git des deux dépôts et des issues GitHub. Chaque affirmation s'appuie sur un commit, une mesure ou une citation.

---

## Ce qu'il faut retenir en dix lignes

Deux écrans ont dominé ces douze jours : **« Mes Classes » qui restait vide** et **la création de leçon qui rendait 403**. Aucun des deux n'avait une cause unique. « Mes Classes » a demandé **cinq correctifs successifs, dont quatre sans le moindre effet** — chacun réparait un maillon réel d'une chaîne coupée plus loin. La création de leçon a eu **quatre causes empilées**, puis est revenue une seconde fois en production pour une cinquième raison.

Le fil rouge n'est pas technique, il est méthodologique : **les causes étaient dans les journaux de production, et je suis allé les chercher trop tard.** Sur « Mes Classes », la première ligne du journal donnait la réponse ; vous avez dû me le dire deux fois. Sur la création de leçon en local, j'ai diagnostiqué par déduction et corrigé le mauvais compte avant d'ouvrir `storage/logs`.

Trois de vos remarques ont plus fait avancer le dossier que mes heures d'analyse : « **es-ce que nous n'exploitons pas la mauvaise api ??** », « **dans dashboard les classes s'affichent, je me dis que tu vas trop loin alors que la solution est juste à côté** », et « **lis attentivement les flux d'échange, lis le code et ne code pas pour coder** ».

---

## Chiffres

| Indicateur | Valeur |
|---|---|
| Problèmes distincts touchant le parcours enseignant | **138** |
| Résolus et déployés | 66 |
| Partiellement résolus | 20 |
| Tracés en dette (issue ouverte, non corrigés) | 18 |
| Non résolus | 34 |
| Commits sur la branche d'intégration `lms` (septembre) | 40 |
| PRs backend du chantier enseignant | #749, #750, #751, #752, #753, #754, #756 |
| PRs frontend | #363, #364 |
| Issues encore actives sur ce parcours | #739, #744, #757, #758, #759, #760 |

---

# PARTIE 1 — La création de leçon (403)

C'est le problème que vous avez signalé le plus souvent. Il a eu **deux vies** : d'abord en local début septembre, puis de nouveau en production le 9 septembre.

## 1.1 Première vie — quatre causes empilées (6-7 septembre)

> Votre demande : « *UNE FENETRE a fait des modification en local car j'avais remarqué que je n'arrivais pas à créer des leçons […] et le code a régressé* »

### Cause n°1 — le PHP local n'avait aucun magasin de certificats

Après la bascule de l'URL KLASSCI en `https`, `curl.cainfo` et `openssl.cafile` étaient vides et aucun `cacert.pem` n'existait sur le poste. Le renouvellement du jeton échouait en **cURL error 60**, puis retombait silencieusement sur un compte semé (id=3, `klassci_id`=200001) **sans jeton**.

**Correctif** : bundle Mozilla officiel installé (121 certificats), `php.ini` pointé dessus. Sauvegarde préalable : `php.ini.avant-cainfo-2026-09-06`.

### Cause n°2 — plus aucun worker de file en local, et c'était de mon fait

Depuis la PR **#730** (`f2d366cb`), la synchronisation d'une classe est différée dans un job `SyncKlassciClasse`. En production un worker tourne. En local, `QUEUE_CONNECTION=database` et **aucun worker** : deux jobs attendaient depuis des heures, la table `classes` restait vide.

C'est mon changement qui a introduit cette dépendance, **et je ne l'avais pas signalé**.

**Correctif** : drainage de la file, puis consigne permanente — `php artisan queue:work database --queue=high,default,low` à côté de `php artisan serve`.

### Cause n°3 — `StoreLessonRequest` exige que la classe existe localement

Zéro classe en base ⇒ **403 systématique**, avec un message qui parle de permissions et ne dit rien de la vraie cause.

### Cause n°4 — Laravel exécute `authorize()` avant la validation

Un champ absent devient donc **403** au lieu de « champ requis ». Le message était doublement trompeur.

### Ce qui a marché

Ouvrir `storage/logs`. Le journal donnait la cause en une ligne : `cURL error 60: SSL certificate problem`, puis `Renouvellement token KLASSCI impossible, fallback local`. Preuve ensuite par appel API réel : `POST /api/lessons classe_id=1 → 200`, `classe_id=4 → 200`.

### Ce qui n'a PAS marché

- **Première fausse piste** : chercher une régression de l'autre fenêtre. Les deux dépôts étaient **propres**, zéro fichier modifié — il n'y avait rien à annuler.
- **Deuxième fausse piste, plus coûteuse** : j'ai diagnostiqué **par déduction** un `klassci_tenant_url` resté en `http://` sur `user#9`, je l'ai corrigé, et je me suis trompé — vous ne vous connectez jamais sur ce compte. Aveu : *« j'ai diagnostiqué par déduction au lieu d'aller lire le journal tout de suite »*.
- **Piste écartée** : votre proposition d'écraser la base locale par celle de la production. Irréalisable (local = SQLite, prod = MySQL) et illégale sur le fond — rapatriement des données personnelles réelles de 13 étudiants.
- J'ai modifié directement des fichiers dans votre checkout pour vous débloquer (1 fichier, puis 7, puis 15). Pratique que j'ai moi-même signalée comme problématique.
- **Le correctif n'a pas suffi** : mon appel API direct passait, celui du frontend continuait d'échouer. C'était le problème suivant.

## 1.2 La leçon partait sur la MAUVAISE matière — en 201, sans erreur

> Votre signalement : « *je viens de créer une leçon, mais je vois que la première que j'ai créée est toujours rattachée à l'anglais* »

**C'est le défaut le plus grave de tout le chantier**, parce qu'il ne produisait aucune erreur.

Le frontend envoyait `matiere_id: matiereId` — le **paramètre de route**, donc un identifiant **KLASSCI** — alors que `classe_id` était déjà un identifiant **local**. Le backend stocke `lessons.matiere_id` en local et résout en faveur du local.

Chez vous, `3` existait dans les deux espaces :

```
id local 3   = Algorithme
klassci_id 3 = Anglais
```

Une leçon créée depuis Anglais atterrissait sur Algorithme, **en HTTP 201, sans la moindre erreur**, et invisible sur la page d'origine.

**Règle actée en conséquence** : *l'API n'émet que l'espace qu'elle stocke.* La réponse porte désormais `matiere_id_local` à côté du bloc `matiere` inchangé (ajout purement additif), et le frontend envoie ce champ. Quand la matière n'est pas miroitée, le champ vaut `null` et le front n'envoie pas de matière : **une leçon sans matière est réparable, une leçon sur la mauvaise matière est une corruption silencieuse**.

### Ce qui n'a PAS marché

- Ce défaut était **le même** que celui déjà corrigé pour `classe_id`, et je ne l'avais vu qu'à moitié : *« classe_id venait d'une liste, je l'avais traité ; matiere_id vient de la route, je ne l'avais pas vu. Je n'avais pas fait le tour du problème. »*
- Il avait été **prédit** par une revue adversariale antérieure. Je l'avais classé faible.
- Premier test écrit = **faux vert** : il vérifiait le résolveur, pas que la réponse porte le champ.
- `assertJsonPath(..., null)` **passe aussi quand la clé est absente** — deux tests sur trois étaient verts à vide, durcis ensuite avec `assertJsonStructure`.
- Côté frontend, deux remplacements sur quatre **n'ont pas pris** (accents non concordants) et ont laissé le front cassé un instant.
- **Vos données n'ont jamais été réparées** : au 9 septembre, la leçon #16 est toujours supprimée par erreur et la #17 toujours sur Algorithme.

## 1.3 La page mentait : en-tête « Marketing digital », leçons d'Anglais

> Conséquence directe : **vous avez supprimé la mauvaise leçon.**

Le même défaut d'espaces, pris **par l'autre bout** — le sens **navigation**. `useLessonChapters.js` (lignes 73 et 97) faisait `params: { id: lesson.value.matiere_id }` : le backend **stocke du local**, le frontend le réinjectait dans une route proxifiée vers KLASSCI.

Chez vous, `1` lu comme id local = Anglais, lu comme id KLASSCI = Marketing digital.

**Correctif** : `GET /api/lessons/{id}` porte désormais `matiere_klassci_id`, pendant exact de `matiere_id_local`, avec traduction bornée à l'institution **de la leçon** (pas du demandeur). Le bouton Retour retombe sur `router.back()` si seul l'id local est connu, plutôt que d'ouvrir une matière au hasard.

### Ce qui n'a PAS marché

- Le défaut était **antérieur** au lot mais **inatteignable** tant que la création échouait en 403 : le corriger l'a rendu atteignable.
- Le rouge de la PR frontend #363 a révélé que **je n'avais jamais lancé Vitest** : j'avais changé la signature de `buildLessonPayload` sans regarder qui l'appelait dans les tests.

## 1.4 Le premier chapitre refusé, puis 500 en production MySQL

Trois règles contradictoires sur `chapters.order` faisaient refuser le **premier** chapitre d'une leçon (ordre 0 rejeté en 422).

Puis, plus grave : `chapters.matiere_id` est **NOT NULL sous MySQL** mais la reconstruction de table SQLite d'une migration l'avait rendue **nullable**. Les deux jambes de la CI n'avaient plus le même schéma — et mon correctif frontend, en envoyant `matiere_id: null`, **augmentait le risque de 500 en production**.

**Correctif** : migration `2026_09_07_180000_make_chapters_matiere_id_nullable.php` — un chapitre ne peut pas être plus contraint que sa leçon.

### Ce qui n'a PAS marché

- Un test `test_the_zero_order_is_stored_verbatim` était **vert sur une leçon sans le moindre chapitre** (`(int) null === 0`). La revue adversariale me l'avait signalé ; **je l'avais classé faible et ignoré**. La jambe MySQL de la CI l'a prouvé.
- PHPStan en CI était rouge sur six occurrences de `static::` sur une méthode privée : **la CI est plus stricte que l'analyse locale**.

## 1.5 Seconde vie — le 403 revient en production, uniquement sur Anglais (9 septembre)

> Votre signalement : « *j'ai testé, j'accède aux classes mais les problèmes de création de leçon ont repris […] mais uniquement pour anglais sinon les autres fonctionnent* »

`classes_concernees` était **vide**. Ses deux jambes sont **locales** — les séances de la matière, et le miroir `classe_matiere` — et elles peuvent être **muettes ensemble**. Anglais n'a aucune séance, et le miroir est vide en production. Le frontend envoyait `classe_id: null`, `StoreLessonRequest::authorize()` refusait.

**Le défaut n'était pas propre à Anglais : il frappait toute matière sans séance.**

**Correctif (#755 → PR #756)** : une **troisième jambe**, en dernier recours seulement — les classes de l'enseignant via `me/teacher-dashboard`, puis leurs matières en un appel groupé, en gardant celles que KLASSCI rattache à la matière. Les identifiants rendus restent **locaux**. `classe_matiere` est alimenté au passage.

**Source exacte, jamais déduite.** Le croisement filière × niveau donnait le même résultat sur ce jeu de données, mais **par déduction** — jugé inacceptable sur une valeur qui finit dans `lessons.classe_id`, seul verrou de visibilité étudiante.

### Ce qui n'a PAS marché — trouvé par revue adversariale APRÈS mon premier jet

1. **L'écriture du miroir était hors du `try/catch`.** `ClasseMatieresSynchronizer::link()` fait `exists()` puis `insert()` sans atomicité sous un index unique : deux requêtes concurrentes lèvent 1062 ou 1213, et la `QueryException` traversait jusqu'au contrôleur, qui la classait en panne KLASSCI et rendait **500 sur une page pourtant entièrement calculée**, sans même un log d'erreur.
2. **La jambe se déclenchait pour tous les rôles**, y compris les étudiants — un aller-retour réseau que rien n'amortit, répété à chaque affichage.
3. **Le pire : le correctif n'était tenu par aucun test.** Le 4ᵉ paramètre était optionnel. Mesure : **retirer le câblage rétablit le 403 et 138 tests restent verts**. Il est devenu obligatoire et non-nullable — l'oubli est désormais une erreur fatale.

J'avais aussi introduit **mon propre N+1** (une requête par classe dans la boucle), corrigé avant commit avec une assertion sur le nombre de requêtes.

**Ce lot laisse deux défauts ouverts** : **#757** (les coordinateurs restent en 403) et **#760** (l'onglet Classes navigue avec un id local vers une route KLASSCI).

---

# PARTIE 2 — « Mes Classes » vide

> « *j'ai fait des modifications dans une autre fenêtre et tout est cassé […] mes classes ne s'affichent pas* »
> Puis, après trois correctifs déployés : « *la correction n'est pas encore effective* », « *fais un test pour te rassurer car je le fais mais j'ai toujours les mêmes résultats* », « *je veux une correction fonctionnelle et des preuves* ».

Le tableau de bord annonçait **4 classes**. La page « Mes Classes » en affichait **0**.

## Les quatre maillons, découverts un par un

| # | Maillon | Mesure |
|---|---|---|
| 1 | La PR frontend #364 a basculé la source de l'écran vers `/lms/teacher/classes`, qui résout par `classe_matiere.enseignant_id` | colonne présente, **toutes valeurs nulles**, **aucun écrivain** dans le dépôt |
| 2 | Le « repli KLASSCI » n'en était pas un : `KlassciEnrollmentSource` interrogeait **exactement la même colonne** — un copier-coller de la source locale | deux chemins, une seule requête, morte de la même façon |
| 3 | `ClasseSyncService::syncUserClasses()` **n'avait aucun appelant** — ses seules mentions étaient des `@see` de docblocs | code mort |
| 4 | Branché ensuite **au login**, seul chemin où l'URL KLASSCI ne peut pas être résolue | `matiere_enseignant` 0, `classe_matiere` 0, `jobs` 0 |

Le maillon 4 mérite une explication, parce qu'il éclaire aussi une dette plus ancienne : **au login, aucun utilisateur Sanctum n'existe encore**. Le résolveur d'URL retombe donc sur `config('services.klassci.url')`, qui est **NULL en multi-tenant**. L'exception est avalée par un `catch`, et **ni le linker ni le dispatch ne s'exécutent jamais**.

La cause était dans la **première ligne du journal de production** : `Erreur sync matières au login — URL de base KLASSCI absente ou invalide`.

## Le cinquième correctif, celui qui a marché

`/lms/teacher/classes` demande ses classes **à KLASSCI sur la requête authentifiée** (`me/teacher-dashboard`), et le miroir local devient le **repli**. Identifiants rendus dans l'espace **KLASSCI** — parce qu'ici rien n'est stocké : la valeur sert à fusionner et à naviguer. Tri alphabétique stable. Effectifs `null` quand inconnus — « je ne sais pas », pas « zéro ».

**C'est votre remarque qui a débloqué le dossier** : *« dans dashboard les classes s'affichent, je me dis que tu vas trop loin alors que la solution est juste à côté »*. Elle a orienté vers le seul chemin authentifié qui résout l'URL.

### La preuve, à trois niveaux

1. 2 370 tests verts ;
2. exécution sur **votre base de dev réelle** : 4 classes, ids 1/2/4/5 ;
3. **navigateur** : « 1ʳᵉ année BTS Génie Civil 2/35 · B2 COM 6/30 · B3 COM 5/30 · ROSTAN 0/30 ».

Le détail qui vaut preuve : **B3 COM et ROSTAN ne sont pas miroitées**, le backend a renvoyé `null`, et les chiffres viennent de la fusion frontend — qui n'aurait jamais apparié avec des ids locaux.

### Un second défaut, invisible tant que la liste était vide

Le service renvoyait l'id **local**, alors que le frontend fusionne notre réponse avec `/proxy/classes` **par id**, et ce référentiel est du **KLASSCI**. Même les miroirs remplis, les effectifs seraient restés à « — ».

### Ce qui n'a PAS marché — trois PR livrées, testées, déployées, sans aucun effet

- **PR #751** : le repli lit enfin `matiere_enseignant`. **Inerte** — `classe_matiere` restait à 0 en production.
- **PR #752** : le job `SyncUserClasses` est posé. **Inerte** — branché au login, il ne pouvait pas résoudre l'URL. *« Mes tests l'appelaient directement : ils prouvaient qu'elle fonctionne, jamais qu'elle est atteinte. »*
- **PR #753** : déclencheur déplacé vers `MyMatieresQueryService`. **Inerte encore** — j'avais **supposé** que le Dashboard appelait cet endpoint. Le journal ne contenait **aucune** ligne `MyMatieres request` : le Dashboard passe par le proxy KLASSCI direct.

Autres échecs de ce lot :

- **Deux tests étaient VERTS sur un comportement impossible en production** : ils posaient eux-mêmes `services.klassci.url` dans leur `setUp()`. Supprimés au profit de `LoginCarriesNoKlassciSyncTest`, qui ne pose pas cette configuration.
- **J'ai présumé l'infrastructure** : j'ai déduit « cPanel » d'un en-tête Apache. Vous avez corrigé : *« je suis sur contabo et j'utilise dokploy […] vérifie comment le déploiement se fait au lieu de supposer »*. Vérification faite : Dokploy v0.30.0, déploiement **automatique** en ~4 minutes.
- Écrire l'enseignant dans `classe_matiere.enseignant_id` a été **explicitement écarté** : cette table porte une ligne par couple (classe, matière) — le dernier connecté effacerait le précédent. Même famille de fuite que #707.
- **Aveu final** : *« j'ai livré quatre PR pour un défaut dont la première ligne du journal de production donnait la cause. Vous avez dû me le dire deux fois avant que je regarde. »*

**Garde posée pour que ça ne se reproduise pas** : `NoOrphanSyncMethodTest` — toute méthode publique `sync*` doit avoir un appelant hors de sa propre classe. Elle a mesuré 9 méthodes, 1 orpheline.

---

# PARTIE 3 — Les autres problèmes enseignant, par thème

## 3.1 Connexion et accès (28 août → 4 septembre)

| Problème | Cause | Statut |
|---|---|---|
| **Personne, enseignant compris, ne pouvait se connecter au LMS déployé** | `VITE_API_URL` sans `/api`, puis CORS mal posé ; et derrière Traefik/Dokploy tout `POST /api` repartait en **302** | Résolu (configuration Dokploy) |
| **« Mes matières » et « Mes séances » répondaient 401 « Token KLASSCI non trouvé. Veuillez vous reconnecter »** alors que le jeton était valide | Un `catch (RuntimeException)` fourre-tout dans 9 fichiers confondait *jeton absent* et *KLASSCI répond 4xx*. La vraie cause n'existait que dans les journaux : KLASSCI répondait **404 « Profil enseignant introuvable »** | Résolu — issue #662, PR #664 |
| **Connexion enseignant impossible en production : 503** | KLASSCI avale un SYN ; le disjoncteur ouvre 30 s pour tout le monde | Partiel — #685, #744, PR #721/#746/#747 |
| **Un compte qui se trompe d'identifiant reçoit « service indisponible » au lieu de « identifiants incorrects »** | Même confusion 4xx / panne | **Non résolu** — #687 |
| **Le compte supradmin, pourtant local, tombe aussi en 503** | Le repli local du login est mort : 10 comptes sur 11 ne peuvent pas se connecter sans KLASSCI | **Non résolu** — #687, épique #697 |
| **Un conflit d'email empêche définitivement la connexion d'un compte KLASSCI** | — | **Non résolu**, aucune issue |

### Ce qui n'a PAS marché sur ce thème

- J'ai affirmé « personne ne peut se connecter à cette école » **sur lecture de code, sans tester**. Faux — vous avez dû me fournir vos identifiants pour le prouver.
- J'ai testé `/api/lms/dashboard/stats`, qui **n'existe pas**, et présenté ce 404 comme un symptôme. La vraie route est `/api/dashboard/stats`.
- Deux hypothèses avancées puis abandonnées : « ton KLASSCI local expose `me/teacher-dashboard`, celui de production non » et « superadmin n'est pas reconnu comme enseignant ». **La vraie réponse était écrite en clair dans le corps de la réponse HTTP.**
- Correctif d'abord incomplet : **2 services sur 6** convertis, et `matiereDetails` est passé de 401 à **500** — régression attrapée par les tests existants.
- `KlassciUnavailableException` était avalée par mon nouveau trait et **perdait son en-tête `Retry-After`** : une panne temporaire devenait une erreur définitive.

## 3.2 Les écrans qui affichaient zéro ou faux

| Problème | Cause | Statut |
|---|---|---|
| **L'enseignant se connecte avec 0 matière et 0 classe** | **KLASSCI se contredit lui-même** : `auth/me` renvoie `{nb_matieres: 0, nb_classes: 0}` tandis que `me/teacher-dashboard`, **même jeton, même seconde**, renvoie 6 matières et 4 classes — les deux en 200 | Résolu par le changement de source |
| **« Mes séances » affiche 0 alors que KLASSCI en connaît 28** | Le LMS interrogeait les mauvais endpoints ; les séances viennent de l'emploi du temps | Résolu — PR #741 |
| **L'onglet « Matières » d'une classe affichait 452 matières** — le catalogue de tout l'établissement | Endpoint non filtré par porteur | Résolu — PR #671 |
| **Écran détail d'une matière : 0 évaluation affichée** sur une matière qui en a 9 | Filtre trop strict, puis doublon HTTP et N+1 | Résolu — #686, #695, #699 |
| **`total_heures: 0`** présenté comme une mesure | Des zéros qui se faisaient passer pour des données | Résolu — PR #671, #676 |
| **Cliquer une classe rendait 500** | Le LMS redemandait le trombinoscope à un endpoint qui refuse | Résolu — PR #669 |
| **`/admin/matieres` ne répondait pas sous 90 s** | 452 appels séquentiels | Résolu — PR #676 |
| **Ouvrir une fiche de classe consommait un worker pendant 2 minutes** | `/lms/seances/upcoming` demandait les 452 matières | Résolu — PR #725 |
| **Le calendrier de l'enseignant ment : 200 avec une liste vide au lieu d'une erreur** | — | **Dette**, aucune issue |
| **Le détail d'une séance rend toujours zéro** | Trois à quatre lecteurs consomment encore une clé KLASSCI morte | **Non résolu** — #740, #739 |

### Ce qui n'a PAS marché sur ce thème

- J'ai tourné **des heures** autour de causes réseau et de causes LMS avant que vous ne posiez la bonne question : « *es-ce que nous n'exploitons pas la mauvaise api ??* ». Ma réponse : *« Tu as raison, et ta question est meilleure que tout ce que j'ai fait depuis deux heures. »*
- J'avais construit une théorie de repli local sur `ManagerSeancesLocalFetcher` avant de découvrir que ce fetcher portait **le même défaut de confusion d'espaces** que #707. J'ai dû **arrêter l'implémentation** : *« je propagerais le défaut sur un chemin bien plus fréquenté »*.
- Un audit croisé lancé en parallèle a rendu **10 affirmations dont 9 réfutées par ses propres vérificateurs** — rapport inexploitable, tout re-vérifié à la main.

## 3.3 Sécurité et autorisations entre enseignants

| Problème | Statut |
|---|---|
| **Un enregistrement visio pouvait être publié dans le cours d'un AUTRE enseignant** | Résolu — #707, PR #722 |
| **Un enseignant pouvait supprimer la séance d'un autre enseignant** (deux règles d'autorité contradictoires) | Résolu — #698, PR #734 |
| **Le secret de signature des accès visio, commité dans un test**, permettait de forger un accès modérateur | Résolu — PR #668 |
| **Le test « amiral » d'isolation multi-établissement était un faux vert** : il passait grâce aux filtres des contrôleurs, pas grâce au scope | Résolu — #709, PR #737 |
| **Les factories tiraient des identifiants KLASSCI au hasard**, faisant passer par chance des assertions d'autorisation | Résolu — #682, PR #715 |
| **Fuite entre établissements : un compte sans institution voit les leçons, forums, classes et matières de tous** | **Non résolu** |
| **Un compte au rôle « enseignant » sans institution lit les données de TOUTES les écoles** | **Non résolu** |
| **Les tests de refus « X ne peut pas » sont verts sans rien prouver** | **Non résolu** — #691 |

## 3.4 Visio et enregistrement

| Problème | Statut |
|---|---|
| La salle s'ouvrait sur `meet.jit.si` et réclamait un compte Gmail | Résolu — #297, PR front #360/#361 |
| Le repli silencieux vers un opérateur public **envoyait le jeton (nom + e-mail) chez meet.jit.si** | Résolu — PR front #344 |
| **Le LMS affirme « la séance est enregistrée » alors que rien ne l'est** | Partiel — #673, #680 |
| Un enregistrement bloqué en « Recording » verrouille la séance pour toujours | Résolu — #680, PR #681 |
| La présence mesurée est raccourcie à chaque « Rejoindre » | Résolu — #683 |
| **L'enregistrement d'un cours ne devient jamais un chapitre dans la formation** | Partiel — #469 **jamais vu fonctionner de bout en bout** |
| Deux enseignants qui enregistrent en même temps : le second perd son cours | **Non résolu** — #706 |
| L'activation visio résout la séance par une clé toujours vide | **Non résolu** — #739, prod-critical |
| Jicofo marqué unhealthy en permanence : le voyant ne surveille rien | **Non résolu** — #675 |

## 3.5 Contenu, chapitres, évaluations

| Problème | Statut |
|---|---|
| **La corbeille des chapitres était un leurre** : fichiers détruits avant, aucune restauration | Résolu — #689, PR #694 |
| Les chapitres supprimés restaient indéfiniment, fichiers compris | Résolu — #674, PR #696 |
| Le rattrapage de suppression existait côté serveur mais restait **invisible** pour l'enseignant | Résolu — PR front #362 |
| **L'évaluation close le soir était supprimée dès 3 h du matin**, copie en cours de rédaction comprise | Résolu — #705, PR #733 |
| **Un job planifié aurait désactivé TOUTES les séances de l'établissement** | Résolu — #661, PR #663 |
| **L'archivage détruisait les séances programmées à plus de 14 jours avant qu'elles aient lieu** | Résolu — #704, PR #732 |
| La base refusait toute séance, évaluation ou classe **sans identifiant KLASSCI** | Résolu — #710, PR #745 |
| **Les deux boutons d'export de présences tapent dans le vide** | **Non résolu** — #726 |
| Le relevé de présence n'est pas opposable | **Non résolu** — #717 |
| **La classe de la leçon est imposée** : l'enseignant ne peut pas choisir | **Dette**, aucune issue ouverte |

## 3.6 Infrastructure et outillage qui ont mordu le parcours enseignant

| Problème | Statut |
|---|---|
| **Le worker de production ne consommait qu'une file sur trois** : les diapositives ne se seraient jamais converties | Résolu — PR #656/#657 |
| **La base de développement locale a été écrasée** | Résolu — garde #703, PR #731 |
| **La base de test se corrompt** et fait échouer 94 tests sans rapport | **Non résolu** — #692 (reproduit à nouveau le 9 septembre) |
| Deux gardes de taille CI passaient au vert **sans rien inspecter** | Résolu — #701, PR #728 |
| Une suite `--filter` vide faisait passer la CI au vert | Résolu — #702, PR #729 |
| **La procédure de restauration n'a jamais été exercée** | **Non résolu** — #727 |
| La purge d'une institution échoue par construction | **Non résolu** — #719, #690 |
| Le seeder de supradmin ne crée rien et annonce un succès | **Non résolu** — #688 |
| Le bouton « Tester la connexion » déclare KLASSCI joignable quand il ne l'est pas | **Non résolu** — #713 |
| La réinitialisation de mot de passe est inutilisable | **Non résolu** — #714 |

---

# PARTIE 4 — Ce qui n'a pas marché, vu de haut

Ces erreurs se répètent. Elles méritent d'être lues ensemble.

## 4.1 J'ai supposé au lieu de mesurer — sept fois

| Ce que j'ai supposé | La réalité | Coût |
|---|---|---|
| « La production tourne sur cPanel » (déduit d'un en-tête Apache) | Contabo + Dokploy, déploiement automatique | Vous avez dû me corriger |
| « Le Dashboard appelle `/lms/teacher/my-matieres` » | Zéro ligne dans le journal ; il passe par le proxy KLASSCI | **Une PR entière inutile (#753)** |
| « `klassci_tenant_url` en http sur user#9 est la cause » | Vous ne vous connectez jamais sur ce compte | Correctif sur le mauvais compte |
| « Personne ne peut se connecter à cette école » | Faux, prouvé par vos identifiants | Diagnostic à refaire |
| « Aucun autre écrivain n'existe pour cette table » | `MatiereSyncService` écrit depuis #258 | **Perte de données réelle** (`code`, `description` remis à null) |
| « Les échecs Vitest sont préexistants » | Le commit parent était vert | Fausse accusation |
| « KLASSCI est joignable » (déduit d'un code HTTP) | Il répondait 500 dans le corps | Erreur commise **deux fois le même jour** |

**La règle qui en sort** : un code HTTP ne prouve rien, il faut lire le **corps** ; et le journal de production dit la vérité avant n'importe quelle déduction.

## 4.2 Des tests verts qui ne prouvaient rien — six fois

1. Deux tests du login posaient **eux-mêmes** `services.klassci.url` : ils testaient un comportement **impossible en production**.
2. `test_the_zero_order_is_stored_verbatim` était vert **sur une leçon sans chapitre**.
3. `assertJsonPath(..., null)` **passe quand la clé est absente** — deux tests verts à vide.
4. Un test vérifiait le résolveur au lieu de vérifier la réponse.
5. Mes tests appelaient `syncUserClasses()` directement : ils prouvaient **qu'elle fonctionne**, jamais **qu'elle est atteinte**.
6. **Le pire** : le câblage de la troisième jambe n'était tenu par rien — le retirer laissait **138 tests verts** et rétablissait le 403.

**Les gardes posées en réponse** : `NoOrphanSyncMethodTest` (toute méthode `sync*` doit avoir un appelant), paramètre rendu obligatoire et non-nullable, falsification systématique (casser la garantie et vérifier que le test rougit).

## 4.3 J'ai corrigé à moitié — trois fois

- `classe_id` traité, `matiere_id` oublié, parce que l'un venait d'une liste et l'autre d'une route.
- Le sens « entrée » traité, le sens « navigation » oublié.
- 2 services sur 6 convertis, avec une régression 401 → 500 au passage.

## 4.4 Pièges d'outillage rencontrés, et comment les éviter

| Piège | Effet | Parade |
|---|---|---|
| Passer un **répertoire** à Pint | Reformate des fichiers étrangers au changement | Toujours lister les fichiers |
| Patcher via Python sur Windows | CRLF → diff du fichier entier | `newline=''` ou l'outil d'édition |
| Séquences `\U`, `\M`, `\S` en Python | Le script casse ou n'écrit rien **silencieusement** | `chr(92)`, vérifier après écriture |
| Agents lançant PHPUnit en parallèle | **Corrompt `database.testing.sqlite`** | Interdire l'exécution de tests aux agents (issue #692) |
| PHPStan CI plus strict que local | Rouge en CI, vert chez soi | Lire l'erreur exacte du job CI |
| **Cache PHPStan chaud en CI** | Masque un défaut latent ; **toute PR y tombe** | Découvert le 9 septembre, corrigé dans #756 |
| Titre de commit > 70 caractères | Commitlint bloque | Vérifier avant de pousser |

---

# PARTIE 5 — Tous les fichiers modifiés

## 5.1 Backend — le chantier identifiants et classes

**PR #749 — séparer les deux espaces d'identifiants** (33 fichiers)

```
app/Http/Requests/StoreChapterRequest.php
app/Http/Requests/StoreLessonRequest.php
app/Models/Classe.php
app/Models/Contracts/MirroredFromKlassci.php          (nouveau)
app/Models/Lesson.php
app/Models/Matiere.php
app/Models/Traits/ResolvesMirroredIdentifier.php      (nouveau)
app/Rules/MirroredIdentifierExists.php
app/Services/ClasseSyncService.php
app/Services/Lesson/LessonCrudOperationsService.php
app/Services/Lesson/LessonListService.php
app/Services/Matiere/MatiereClassesExtractor.php
app/Services/Matiere/MatiereClassesResolver.php
app/Services/Matiere/MatiereDetailsQueryService.php
app/Services/Matiere/MatiereSeancesFetcher.php
app/Services/Sync/Classes/ClasseMatieresSynchronizer.php
app/Services/Sync/Classes/KlassciClassesFetcher.php
database/migrations/2026_09_07_180000_make_chapters_matiere_id_nullable.php
+ 14 fichiers de tests
```

**PR #751 — le repli enseignant interrogeait la source locale** (8 fichiers)

```
app/Services/Enrollment/KlassciEnrollmentSource.php
app/Services/Enrollment/TeacherMatieresLinker.php     (nouveau)
app/Services/Klassci/Auth/KlassciUserSynchronizer.php
app/Services/MatiereSyncService.php
+ 4 tests
```

**PR #752 — la synchro des classes n'avait aucun appelant** (5 fichiers)

```
app/Jobs/SyncUserClasses.php                          (nouveau)
app/Services/Klassci/Auth/KlassciUserSynchronizer.php
tests/Feature/Architecture/NoOrphanSyncMethodTest.php (nouveau, garde)
+ 2 tests
```

**PR #753 — la synchro était branchée sur le seul chemin qui ne peut pas** (7 fichiers)

```
app/Services/Klassci/Auth/KlassciUserSynchronizer.php
app/Services/Matiere/MyMatieresQueryService.php
tests/Feature/Auth/LoginCarriesNoKlassciSyncTest.php  (remplace 2 tests faux verts)
+ 4 tests
```

**PR #754 — les classes viennent de l'amont, le miroir devient un repli** (3 fichiers)

```
app/Services/Classe/TeacherClassesQueryService.php
+ 2 tests
```

**PR #756 — une matière sans séance récupère ses classes chez KLASSCI** (10 fichiers)

```
app/Services/Attendances/AttendanceHistoryQueryService.php  (défaut PHPStan latent)
app/Services/Classe/TeacherClassesQueryService.php
app/Services/Klassci/TeacherDashboardClasses.php            (nouveau)
app/Services/Matiere/KlassciMatiereClassesSource.php        (nouveau)
app/Services/Matiere/MatiereClassesResolver.php
app/Services/Matiere/MatiereDetailsQueryService.php
+ 4 tests
```

## 5.2 Frontend

**PR #363 — envoyer et naviguer dans le bon espace d'identifiants**

```
src/composables/useLessonChapters.js
src/composables/useMatiereDetails.js
src/utils/matiereDetails.js
tests/unit/matiereDetails.test.js
tests/unit/useLessonChapters.test.js
```

**PR #364 — charger « Mes Classes » depuis le LMS local**

```
src/composables/useTeacherClasses.js
src/services/endpoints.js
src/services/lmsClasses.js
tests/unit/lmsServiceSplit.test.js
tests/unit/teacherClassesView.test.js
tests/unit/useTeacherClasses.test.js
```

## 5.3 Les 40 commits de septembre sur `lms`

```
82ab2005  fix(755): une matiere sans seance recupere ses classes chez klassci (#756)
9cd9b096  fix(712): les classes viennent de l'amont, le miroir devient un repli (#754)
2dd3d94f  fix(712): la synchro etait branchee sur le seul chemin qui ne peut pas (#753)
a86a2142  fix(712): la synchro des classes n avait aucun appelant (#752)
702e58ea  fix(712): le repli enseignant interrogeait la source locale (#751)
9d08d592  feat(712): inscriptions locales pour les listes de classes (#750)
c57887eb  fix(klassci): rejouer le pool coupe, sans aggraver les pannes (#748)
42b4f189  fix(klassci): separer les deux espaces d'identifiants (#749)
3c23420b  feat(klassci): sonde planifiee de joignabilite (#747)
4abecaaf  feat(schema): rend les colonnes klassci locales nullables (#745)
088491cb  fix(klassci): rejouer les lectures coupees, tracer le disjoncteur (#746)
759eb936  feat(712): audience visio locale, plus d HTTP KLASSCI (#743)
c99675b7  docs(modele): acte six decisions irreversibles (#742)
ae9839d7  fix(seances): les seances viennent de l emploi du temps (#741)
0a34d9b9  test(isolation): bearer reel et probe de scope tenant (#737)
301adcf3  chore(jobs): borne ExpireQuizAttempts et ajoute failed() (#736)
4f25ffa2  feat(visio): consentement append-only avant enregistrement (#735)
e6c9d2e1  fix(securite): une seule autorite enseignant sur les seances (#734)
dd4b3f01  fix(eval): ne purge plus les copies en cours ni le jour J (#733)
2107d4e1  fix(seances): n archive plus les seances locales par age (#732)
07f65c1a  fix(db): refuse migrate:fresh hors tests sans opt-in (#731)
f2d366cb  perf(seances): sort la synchro de classe du chemin utilisateur (#730)
159d6b20  fix(ci): une suite --filter vide fait echouer la CI (#729)
9e299dd6  fix(ci): deux gardes de taille passaient au vert sans rien inspecter (#728)
5405bdf5  perf(seances): scope les matieres a l'utilisateur, N+1 supprime (#725)
1cc8bbaf  test(visio): parcours bout en bout enregistrement -> chapitre (#724)
397d28ae  chore(ocp): garde Open/Closed executoire pour le chantier V2 (#723)
a4a3cc11  fix(klassci): un KLASSCI injoignable annonce la panne (#721)
ea693814  fix(visio): l enregistrement n atterrit plus chez un collegue (#722)
b5d50d3a  fix(factories): identifiants KLASSCI hors de portee des tests (#715)
947769fb  fix(matiere-details): 0 evaluation affichee, filtre trop strict (#699)
85094e6a  feat(conformite): purge des chapitres a la corbeille (#696)
d2eea99e  fix(matiere-details): reutilise evaluations deja recu, N+1 elimine (#695)
b4b811ad  fix(chapitres): corbeille reelle avec restauration (#694)
dedb7f29  fix(matiere-details): elimine le doublon HTTP matieres/{id} (#686)
fd2ed065  feat(visio): reprise de la participation apres un rechargement (#684)
c0cf6bbb  fix(visio): referme les enregistrements bloques en recording (#681)
081c7ff7  fix(sync): pose le tenant avant chaque appel KLASSCI du job (#679)
f898651c  chore: garde-fou anti-secret et point d'entree documentaire (#677)
75a20ed1  feat(admin): liste paginee des comptes LMS du tenant (#678)
```

---

# PARTIE 6 — Ce qui reste ouvert

## 6.1 Prioritaire

| Issue | Problème | Pourquoi c'est important |
|---|---|---|
| **#760** | Cliquer une classe dans l'onglet Classes d'une matière **affiche la fiche d'une AUTRE classe** | **Déjà actif en production.** Montre une donnée fausse sans rien signaler — en 200, sans erreur |
| **#739** | L'activation visio résout la séance par une clé toujours vide | Prod-critical, bloque le parcours visio complet |
| **#757** | Créer une leçon rend toujours 403 **quand on est coordinateur** | Le correctif de #755 ne couvre que les enseignants |
| **#744** | Un SYN avalé devient 30 s de 503 pour tous | « Parfois ça marche, parfois non » |

## 6.2 Dettes tracées

| Issue | Problème |
|---|---|
| **#758** | Une panne KLASSCI partielle **fige définitivement** une liste de classes amputée |
| **#759** | Le lien classe-matière s'écrit en deux instructions non atomiques et peut rendre 500 |
| **#692** | La base de test se corrompt et fait échouer 94 tests sans rapport (**reproduit le 9 septembre**) |
| **#691** | Les tests de refus « X ne peut pas » sont verts sans rien prouver |
| **#469** | Le parcours visio de bout en bout n'a **jamais** été vu fonctionner |
| — | `MatiereSyncService` échoue au login **depuis #258**, sans que rien ne le dise |
| — | La classe de la leçon est **imposée** : l'enseignant ne peut pas choisir |
| — | Les miroirs enseignant restent **vides en production** : l'écran dépend de KLASSCI à chaque affichage |

## 6.3 Vos données, laissées dans un état incorrect

- **Leçon #16** : supprimée par erreur (à cause du défaut de navigation §1.3), **jamais restaurée**.
- **Leçon #17** : toujours rattachée à Algorithme au lieu d'Anglais, **jamais corrigée**.

C'est une dette que je vous dois.

---

# ANNEXE — Vos demandes, dans vos mots

Les demandes qui ont structuré le chantier enseignant, dans l'ordre.

**Sur la connexion et l'accès**
> « il faut bien regarder en local je le fais bien / le problème sur le déploiement contabo […] je t'envoie les identifiants pour que tu puisses voir de toi-même et le faire un test »
> « essaie de te connecter avec cet identifiant […] qui provient de klassci tu verras et tu verras comment corriger »
> « avant tout tu veux me dire qu'il n'existe pas de solution admin ou super admin dans klassci ?? »
> « pourquoi en local j'ai pas le problème et c'est en prod sur contabo que j'ai ce problème ?? »

**Sur les écrans vides**
> « es-ce que nous n'exploitons pas la mauvaise api ?? »
> « il y a beaucoup d'éléments qui ne s'affichent pas »

**Sur la création de leçon**
> « UNE FENETRE a fait des modifications en local car j'avais remarqué que je n'arrivais pas à créer des leçons […] et le code a régressé »
> « ok donc je peux créer une leçon maintenant ?? » / « je veux tester d'abord »
> « je viens de créer une leçon mais je vois que la première que j'ai créée est toujours rattachée à l'anglais »
> « je te donne carte verte pour résoudre le problème de façon efficace et durable »
> « j'ai testé, j'accède aux classes mais les problèmes de création de leçon ont repris […] mais uniquement pour anglais sinon les autres fonctionnent »

**Sur « Mes Classes »**
> « j'ai fait des modifications dans une autre fenêtre et tout est cassé […] mes classes ne s'affichent pas »
> « la correction n'est pas encore effective / lis attentivement les flux d'échange / lis le code et ne code pas pour coder / vérifie comprends avant de faire des modifications » *(dit deux fois)*
> « fais un test pour te rassurer car je le fais mais j'ai toujours les mêmes résultats »
> « je veux une correction fonctionnelle et des preuves »
> « oui continue à creuser mais une remarque : dans dashboard les classes s'affichent / je me dis que tu vas trop loin alors que la solution est juste à côté »
> « ok j'ai vérifié et c'est ok tu peux pusher »

**Sur la méthode et l'infrastructure**
> « je suis sur contabo et j'utilise dokploy, bannis cpanel dans mon projet / et vérifie comment le déploiement se fait au lieu de supposer »
> « je t'avais bien dit de vérifier avant de répondre mais tu répètes les mêmes erreurs à chaque fois »
> « il y a une erreur qui peut détruire tout l'effort d'une vie »
> « rassure-toi que le travail est persistant et que nous n'aurons plus ce problème »
> « je ne veux plus une régression sur ce point »

---

*Document produit le 9 septembre 2026. Sources : journal de session (19 388 lignes), `git log` des dépôts `lms-backend` et `lms-frontend`, issues GitHub #656 à #760.*
