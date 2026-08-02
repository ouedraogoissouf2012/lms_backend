# Élimination des N+1 SQL dans `UpcomingSeancesFetcher` — chemin « séances à venir » étudiant/enseignant

> Épique GitHub : [#472 \[hardening\] Cache local des séances KLASSCI](https://github.com/ouedraogoissouf2012/lms_backend/issues/472)
> Sous-issue traitée : [#476 \[perf\] Éliminer les N+1 SQL de `UpcomingSeancesFetcher`](https://github.com/ouedraogoissouf2012/lms_backend/issues/476)
> Commit de référence de la dette : `99ffbc512` (#177).
>
> **Nature de ce document : spécification d'une correction de dette de performance MEDIUM pré-existante.** Contrairement à `seances-cache-hardening/requirements.md` (régularisation rétroactive), ce document spécifie un travail **à réaliser** sur la branche `fix/476-seances-n-plus-1`. Chaque REQ décrit le comportement cible et cite les `fichier:ligne` **actuels** (branche `fix/476-seances-n-plus-1`, issue de `lms` à jour, après le refactor #475) où la correction doit s'appliquer.

## Contexte

`app/Services/Seances/UpcomingSeancesFetcher.php` (257 lignes) construit la liste des « séances à venir » pour les rôles non-manager (étudiant, enseignant). Le chemin non-manager (`fetch()`, lignes 42-114) :

1. récupère les matières de l'utilisateur via KLASSCI (`/matieres`) ;
2. pour **chaque matière**, appelle `filterSeances()` (ligne 100) sur ses `seances_programmees` ;
3. concatène les résultats, puis appelle **une seule fois** `enrichWithVisio()` (ligne 113) sur l'ensemble.

Ce chemin lit la table locale `seances` (et `seance_user_hidden`) **une ligne à la fois**, produisant trois N+1 SQL confirmés par lecture directe du code :

| # | Emplacement (fichier:ligne) | Appel | Requête déclenchée | Fréquence |
|---|------------------------------|-------|--------------------|-----------|
| 1 | `UpcomingSeancesFetcher.php:142` (dans `filterSeances`, `->filter(...)`, branche `isStudent()`) | `Seance::where('klassci_seance_id', KlassciPayload::toInt($seance['id']))->first()` | `SELECT … FROM seances WHERE klassci_seance_id = ? LIMIT 1` | **1 par séance** de chaque matière (étudiants) |
| 2 | `UpcomingSeancesFetcher.php:150` (dans le même `->filter(...)`) | `SeanceUserHidden::isHidden($localSeance->id, $user->id)` → `SeanceUserHidden.php:55-60` | `SELECT EXISTS(SELECT … FROM seance_user_hidden WHERE seance_id = ? AND user_id = ?)` | **1 par séance** non-archivée existant en local (étudiants) |
| 3 | `UpcomingSeancesFetcher.php:233` (dans `enrichWithVisio`, `->map(...)`) | `Seance::byKlassciId(KlassciPayload::toInt($seance['id']) ?? 0)->first()` → scope `Seance.php:126-128` | `SELECT … FROM seances WHERE klassci_seance_id = ? LIMIT 1` | **1 par séance** concaténée (**tous les rôles** non-manager) |

### Observation clé — mutualisation possible

Les N+1 **#1** (`filterSeances:142`) et **#3** (`enrichWithVisio:233`) interrogent **la même table `seances` par le même `klassci_seance_id`**. Une **seule** requête `whereIn('klassci_seance_id', $allIds)` peut alimenter les deux lookups, résolus ensuite en mémoire.

### Anti-patterns mesurés (avant correctif)

Soit `n` = nombre total de `seances_programmees` retournées par KLASSCI (toutes matières confondues), `m` = nombre de matières.

| N+1 | Coût actuel (nb requêtes SQL) | Cible | Violation |
|-----|-------------------------------|-------|-----------|
| #1 lookup `Seance` dans `filterSeances` (étudiants) | **O(n)** — 1 `SELECT … LIMIT 1` par séance | O(1) amorti (1 `whereIn` global) | ❌ §1.4 zéro N+1 |
| #2 `SeanceUserHidden::isHidden` (étudiants) | **O(n)** — 1 `SELECT EXISTS` par séance existante | O(1) (1 `whereIn`+`where user_id` → `pluck`) | ❌ §1.4 zéro N+1 |
| #3 lookup `Seance` visio dans `enrichWithVisio` (tous rôles) | **O(n)** — 1 `SELECT … LIMIT 1` par séance | O(1) (mutualisé avec #1) | ❌ §1.4 zéro N+1 |
| **Total table `seances`** | **≈ 2 · O(n)** SELECT + O(n) EXISTS | **2 SELECT + 1 whereIn** constant | ❌ Scalabilité §1.6 |

Coût actuel non borné : un enseignant avec 8 matières × ~20 séances programmées ≈ **160 séances** ⇒ jusqu'à **~320 SELECT `seances` + ~160 EXISTS `seance_user_hidden`** pour une seule requête HTTP « séances à venir ». Croît linéairement avec le catalogue.

### État cible (après correctif)

| Métrique | Avant | Après | Conforme |
|----------|-------|-------|----------|
| SELECT `seances` (chemin étudiant) | O(n) (#1) + O(n) (#3) | **1** `whereIn('klassci_seance_id', $allIds)` mutualisé | ✓ §1.4 |
| SELECT `seance_user_hidden` (chemin étudiant) | O(n) EXISTS (#2) | **1** `whereIn('seance_id', $localIds)->where('user_id', $uid)->pluck` | ✓ §1.4 |
| SELECT `seances` (chemin enseignant, pas de filtrage masqué) | O(n) (#3) | **1** `whereIn` | ✓ §1.4 |
| Nombre de requêtes en fonction du volume `n` | **croît linéairement** | **constant** | ✓ scalabilité §1.6 |
| Résultats fonctionnels (séances filtrées : archivées / masquées / visio enrichie) | — | **identiques byte-à-byte** | ✓ non-régression stricte |
| `UpcomingSeancesFetcher.php` | 257 lignes | ≤ 300 lignes (extraction d'un pré-chargeur si dépassement) | ✓ §1.1 |

## Contrainte de scope tenant à préserver (CRITIQUE)

Les lookups actuels utilisent `Seance::where(...)` (ligne 142) et `Seance::byKlassciId(...)` (ligne 233, scope `Seance.php:126-128`) **sans** `withoutGlobalScope`. En contexte HTTP, le global scope `institution` (trait `BelongsToInstitution`) est **actif** et filtre déjà par tenant courant. Idem pour `SeanceUserHidden` (le modèle utilise `BelongsToInstitution`, `SeanceUserHidden.php:17`).

**Le pré-chargement `whereIn` DOIT rester un accès Eloquent normal** (`Seance::whereIn(...)`, `SeanceUserHidden::whereIn(...)`), **sans** `withoutGlobalScope('institution')`, afin que le scope global continue de restreindre au tenant courant **exactement comme les lookups unitaires actuels**. Ajouter `withoutGlobalScope` ici serait une **régression de sécurité** (fuite cross-tenant) : ce chemin s'exécute en HTTP, pas dans un job.

## Requirements (EARS)

### REQ-1 — Pré-chargement en une requête des `Seance` locales par `klassci_seance_id`

WHERE `UpcomingSeancesFetcher` doit résoudre des `Seance` locales à partir d'identifiants KLASSCI sur le chemin non-manager,
THE service SHALL collecter l'ensemble des `klassci_seance_id` non nuls des séances concernées et charger les entités `Seance` correspondantes en **une seule** requête `Seance::whereIn('klassci_seance_id', $allIds)->get()->keyBy('klassci_seance_id')`, produisant une map indexée en mémoire.

WHERE cette requête de pré-chargement est émise,
THE service SHALL la laisser passer par le **global scope `institution`** (accès Eloquent standard, **sans** `withoutGlobalScope`), afin de préserver l'isolation tenant HTTP identique aux lookups unitaires remplacés (`:142`, `:233`).

WHEN un `klassci_seance_id` n'a **aucune** ligne locale correspondante,
THE service SHALL le traiter **exactement** comme le `->first()` actuel retournant `null` (séance non archivée, non masquée, visio non configurée) — aucun changement de comportement.

### REQ-2 — Élimination du N+1 `filterSeances:142` (lookup `Seance` étudiant)

WHERE `filterSeances()` filtre les séances pour un utilisateur `isStudent()` (`UpcomingSeancesFetcher.php:140-156`),
THE méthode SHALL résoudre la `Seance` locale de chaque séance **depuis la map pré-chargée** (REQ-1), et NON via `Seance::where('klassci_seance_id', …)->first()` par élément.

WHEN une `Seance` locale existe et porte `is_active = false`,
THE filtre SHALL exclure la séance (masquer les archivées aux étudiants) — comportement identique à `:145-147`.

IF aucune `Seance` locale n'existe pour la séance KLASSCI,
THEN le filtre SHALL conserver la séance (visible) — comportement identique au `null` actuel.

### REQ-3 — Élimination du N+1 `SeanceUserHidden::isHidden` (`:150`)

WHERE `filterSeances()` doit écarter les séances masquées par l'étudiant,
THE méthode SHALL pré-charger, en **une seule** requête, l'ensemble des `seance_id` masqués pour l'utilisateur courant : `SeanceUserHidden::whereIn('seance_id', $localIds)->where('user_id', $user->id)->pluck('seance_id')`, où `$localIds` sont les `id` locaux des `Seance` pré-chargées (REQ-1), puis résoudre le masquage par appartenance à cet ensemble en mémoire.

WHERE cette requête est émise,
THE service SHALL la laisser passer par le global scope `institution` (`SeanceUserHidden` utilise `BelongsToInstitution`, `SeanceUserHidden.php:17`) — **sans** `withoutGlobalScope` — préservant l'isolation tenant du `isHidden()` actuel (`SeanceUserHidden.php:55-60`).

WHEN une séance locale existe ET son `id` appartient à l'ensemble des masqués,
THE filtre SHALL exclure la séance — comportement identique à `:150-152`.

WHEN aucune `Seance` locale n'existe pour une séance KLASSCI,
THE filtre SHALL NOT interroger `seance_user_hidden` pour elle (le code actuel ne le fait pas non plus : la condition `:150` est gardée par `$localSeance &&`).

### REQ-4 — Élimination du N+1 `enrichWithVisio:233` (lookup visio), mutualisé avec REQ-1

WHERE `enrichWithVisio()` enrichit chaque séance avec ses champs visio locaux (`UpcomingSeancesFetcher.php:218-256`),
THE méthode SHALL résoudre la `Seance` locale **depuis la map pré-chargée par `klassci_seance_id`** (REQ-1), et NON via `Seance::byKlassciId(…)->first()` par élément (`:233`).

THE map pré-chargée SHALL être la **même** structure que celle alimentant `filterSeances` (REQ-1) — un unique `whereIn` sert les deux call-sites (#1 et #3), conformément à l'observation de mutualisation.

WHEN une `Seance` locale existe pour la séance,
THE enrichissement SHALL positionner `visio_enabled`, `visio_type`, `visio_room_id`, `visio_active`, `visio_started_at`, `visio_ended_at` **identiquement** à `:236-241`.

IF aucune `Seance` locale n'existe,
THEN l'enrichissement SHALL positionner les valeurs par défaut (`visio_enabled=false`, `visio_type=null`, `visio_room_id=null`, `visio_active=false`, `visio_started_at=null`, `visio_ended_at=null`) **identiquement** à `:243-249`.

### REQ-5 — Non-régression fonctionnelle stricte (invariant transversal)

WHEN la même requête « séances à venir » est exécutée avant et après le correctif, pour un même jeu de données (séances KLASSCI, séances locales, archivées, masquées, visio configurée),
THE liste retournée SHALL être **identique** : mêmes séances présentes/absentes (filtre date, classe, archivage, masquage) et mêmes champs visio/`duree_minutes` — à l'ordre près, inchangé.

WHERE l'isolation multi-tenant est concernée,
THE correctif SHALL préserver le filtrage par institution actif en HTTP : les `whereIn` de REQ-1/REQ-3 s'exécutent sous le global scope `institution`, sans `withoutGlobalScope`.

### REQ-6 — Test anti-N+1 avec `QueryLog` et croissance de volume

WHERE un test de performance est ajouté sous `tests/Feature/Performance/` (pattern projet : `SeanceParticipantsCountTest.php`, `TeacherDashboardServiceTest.php:297-322`),
THE test SHALL utiliser `DB::enableQueryLog()` / `count(DB::getQueryLog())` et démontrer le pattern **« baseline vs afterGrowth »** : le nombre de requêtes SQL sur les tables `seances` et `seance_user_hidden` SHALL rester **constant** lorsque le nombre de `seances_programmees` passe d'un petit volume à un volume nettement supérieur (p. ex. 3 → 30 séances), prouvant l'absence de N+1.

THE test SHALL couvrir **le chemin étudiant** (les trois N+1 : #1, #2, #3) **et** le chemin enseignant (#3 seul), en isolant les appels KLASSCI (le fetcher walk KLASSCI ne doit pas être testé en réseau réel — cf. stratégie de design).

### REQ-7 — Conformité PRODUCTION_STANDARDS (invariant transversal)

WHERE le correctif est livré,
THE code SHALL satisfaire :
1. `UpcomingSeancesFetcher.php` ≤ **300 lignes** (§1.1). Le fichier étant à **257 lignes** (marge de 43 lignes), SI l'ajout du pré-chargement et de la logique de résolution en mémoire fait dépasser 300 lignes, ALORS la logique de pré-chargement SHALL être **extraite dans un collaborateur dédié** (p. ex. `LocalSeanceLookup` / `SeancePreloader`) injecté par constructeur (pattern MANIFESTE_REFACTORING.md), et NON inlinée au prix d'un dépassement.
2. Toutes les méthodes ≤ **40 lignes** (§5).
3. **Aucune Facade** (`DB::`, `Log::`, …) en code métier — DI strict §1.6 D (le `DB::enableQueryLog()` n'apparaît **que** dans les tests, bordure autorisée).
4. **Zéro N+1 SQL** sur le chemin corrigé (§1.4), vérifié par le test REQ-6.
5. PHPStan level 9 = 0 erreur (aucune entrée de baseline ajoutée).

## Divergence de numérotation à documenter (issue #476 vs code réel)

L'issue #476 a été rédigée **avant** le refactor #475 et cite d'**anciennes** lignes qui n'existent plus :

| Défaut | Lignes citées dans #476 (pré-#475) | Lignes réelles actuelles (post-#475) |
|--------|-------------------------------------|--------------------------------------|
| Lookup `Seance` visio dans `enrichWithVisio` | 315 / 324 | **233** |
| Lookup `Seance` dans `filterSeances` | 238 / 240 | **142** |
| `SeanceUserHidden::isHidden` | 248 | **150** |

Cause : le refactor #475 (extraction de `ManagerSeancesLocalFetcher`, cf. `seances-cache-hardening/requirements.md` REQ-10) a fait passer `UpcomingSeancesFetcher.php` de **346 à 257 lignes** (et non 246 : la valeur 246 du doc #475 reflétait un état intermédiaire ; le fichier livré sur `fix/476-seances-n-plus-1` fait **257 lignes**, vérifié par lecture directe). Les call-sites des trois N+1 se trouvent désormais aux lignes **142 / 150 / 233**. Ce document fait foi sur les lignes réelles.

## Hors scope (volontairement)

| Item | Pourquoi hors scope |
|------|----------------------|
| **`duree_minutes` calculé sur du code mort dans `enrichWithVisio` (`:223-230`)** | **Bug distinct des N+1, à tracer comme dette (issue de suivi à ouvrir), NE PAS corriger ici.** `enrichWithVisio` lit `$seance['date_seance']`, `$seance['heure_debut']`, `$seance['heure_fin']` **à la racine** (`:223-225`), mais `mapSeances` (`:171-207`) produit ces valeurs **exclusivement sous `programmation.*`** (`:178-190`), **jamais** à la racine `date_seance`. Le commentaire `:169` le confirme (« Le frontend attend `seance.programmation.date`, pas `seance.date_seance` »). Sur le chemin `mapSeances`, la condition `:226` est donc **toujours fausse** et le bloc `duree_minutes` est **du code mort inerte**. Le corriger changerait la sortie JSON (ajout d'un `duree_minutes` aujourd'hui absent) → **hors périmètre #476** pour garder le diff N+1 auditable et strictement non-régressif (REQ-5). À tracer comme dette de suivi. |
| Refonte du walk KLASSCI (`/matieres`, `fetchManyMatieresDetails`) | Optimisation réseau orthogonale (déjà parallélisée #135) ; #476 cible les N+1 **SQL locaux**, pas les appels HTTP KLASSCI. |
| Mise en cache du résultat « séances à venir » (Redis / mémoïsation) | Décision d'architecture distincte ; #476 se limite à supprimer les N+1 par pré-chargement, à comportement identique. |
| Migration du chemin étudiant/enseignant vers `ManagerSeancesLocalFetcher` (lecture 100 % locale) | Le chemin non-manager dépend du walk KLASSCI par conception (cache local partiel) ; unifier les chemins est un chantier séparé. |
| Introduction d'un scope composite `(klassci_seance_id, institution_id)` sur les lookups | La contrainte composite existe déjà côté données (#473) ; le global scope `institution` HTTP couvre le filtrage tenant en lecture. Rien à ajouter ici. |

## Critère d'acceptation global

La PR est mergeable WHEN :

1. ✓ REQ-1 à REQ-7 implémentés et reflétés par le code livré.
2. ✓ Les trois N+1 (`filterSeances:142`, `SeanceUserHidden:150`, `enrichWithVisio:233`) sont supprimés : le nombre de requêtes SQL `seances` / `seance_user_hidden` du chemin non-manager est **constant** vis-à-vis du volume de séances.
3. ✓ Un unique `whereIn('klassci_seance_id', …)` alimente à la fois `filterSeances` (#1) et `enrichWithVisio` (#3) — mutualisation effective.
4. ✓ Les `whereIn` de pré-chargement s'exécutent **sous le global scope `institution`** (aucun `withoutGlobalScope` ajouté) — isolation tenant HTTP préservée.
5. ✓ Non-régression fonctionnelle stricte : mêmes séances filtrées (date, classe, archivées, masquées) et mêmes champs visio qu'avant (REQ-5).
6. ✓ Test anti-N+1 « baseline vs afterGrowth » vert (REQ-6), couvrant chemins étudiant **et** enseignant.
7. ✓ `UpcomingSeancesFetcher.php` ≤ 300 lignes (extraction d'un pré-chargeur si nécessaire) ; toutes méthodes ≤ 40 lignes ; aucune Facade en code métier ; PHPStan level 9 = 0 erreur.
8. ✓ Le finding `duree_minutes` code mort (`:223-230`) est **documenté et tracé comme issue de suivi**, **non corrigé** dans cette PR.
9. ✓ La divergence de numérotation #476 (315/324, 238/240, 248 → 233/142/150) est documentée.
10. ✓ Sous-issue #476 fermée post-merge ; issue de suivi `duree_minutes` ouverte.

## Critère d'invalidation (Q15 — manifeste §4)

Cette solution est **à invalider et reconcevoir** SI :

1. **Le chemin non-manager cesse de walker KLASSCI** et bascule sur une lecture 100 % locale (fusion avec `ManagerSeancesLocalFetcher`) — le pré-chargement `whereIn` par `klassci_seance_id` serait remplacé par une requête locale directe filtrée, rendant la mutualisation REQ-1/REQ-4 sans objet.
2. **`BelongsToInstitution` est promu en `throw` strict** (fin du no-op CRITICAL-06) — il faudrait vérifier que les `whereIn` de pré-chargement résolvent bien un tenant courant en HTTP ; le raisonnement « global scope actif en HTTP » resterait valide mais devrait être re-confirmé.
3. **`SeanceUserHidden` migre vers un stockage non relationnel** (flag dénormalisé sur `seances`, cache par utilisateur) — le pré-chargement `pluck('seance_id')` de REQ-3 serait remplacé par une résolution différente.
4. **KLASSCI expose un endpoint emploi-du-temps corrigé** remplaçant le workaround `/matieres` (`:56-58`) et fournissant directement les métadonnées visio/archivage — le besoin de croiser avec le cache local `seances` par `klassci_seance_id` pourrait disparaître, invalidant les trois pré-chargements.
5. **Le résultat « séances à venir » passe derrière un cache applicatif** (Redis, mémoïsation par requête) — les N+1 deviendraient un coût amorti au cache miss ; le pré-chargement resterait souhaitable mais la stratégie de test (REQ-6) devrait tenir compte du cache.

Aucune de ces 5 conditions n'est connue aujourd'hui.
