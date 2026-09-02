# Classe virtuelle autonome — indépendance vis-à-vis de KLASSCI

> Statut : **recherche + benchmark + conception terminés.** Décisions produit restantes en §7.
> Aucune ligne de code écrite. Recherche menée les 2026-08-28/29 sur la branche `lms` (HEAD `35cd0da8`).

---

## 1. Contexte

Le LMS est aujourd'hui, par conception explicite, un **satellite** de KLASSCI :

> « KLASSCI est l'application CRM centrale qui gère les utilisateurs, les données académiques,
> **l'authentification et les permissions**. LMS Backend est une application **satellite** —
> **pas de duplication de comptes**. » — [docs/INTEGRATION_KLASSCI.md:14-35](docs/INTEGRATION_KLASSCI.md)

**Le besoin exprimé est l'inverse.** Et ce n'est pas une école : c'est **un formateur ou un organisme qui ouvre un espace pour une session de 4-6 mois, forme, enregistre, évalue, clôture et repart**, avec possibilité de revenir et sans obligation de conserver ses données.

Conséquence : la notion racine n'est pas « année scolaire » mais **session de formation datée**, avec un cycle de vie complet. Les deux modes cohabitent — **zéro régression pour les écoles KLASSCI existantes** (décision utilisateur).

---

## 2. Ce que le benchmark établit

Étude de 20+ plateformes (Google Classroom, Teams for Education, Moodle 5.x, Canvas, BBB 3.x, Adobe Connect, Class, Teachable, Thinkific, Kajabi, LearnWorlds, Circle, Skool, **Maven**, **Disco**).

### 2.1 Trois espaces blancs confirmés

| # | Constat | Preuve marché |
|---|---|---|
| E1 | **La session datée n'est l'entité racine de personne.** Google Classroom et Teams n'ont aucune date. Canvas a un `Term` institutionnel inadapté au formateur indépendant. | Seuls **Moodle** (`enrolperiod` + action à expiration) et **Adobe Connect** (Curriculum) l'approchent, sans en faire leur concept central. **Maven** a `Cohort Start/End Date`. |
| E2 | **La fin de vie d'une session n'est modélisée nulle part.** Aucun acteur ne fait archivage + export complet + réactivation. | Kajabi : site inaccessible immédiatement, **données supprimables à 90 j**. Thinkific : « le contenu **peut** être définitivement supprimé », rétention **non garantie**. Teachable : garde sans le promettre. |
| E3 | **La relance d'une session** (dupliquer, décaler tout le calendrier) n'est bien faite que par **Disco**. **Kajabi l'interdit** carrément sur ses cours cohorte. | — |

C'est exactement le triplet décrit dans le besoin. **C'est le différenciant du produit, pas un détail technique.**

### 2.2 Le modèle qui gagne

**`Programme` (contenu réutilisable) 1—N `Session` (dates, inscrits, évaluations)**, avec décalage automatique de tout le calendrier à la duplication. Cycle de vie attendu :

```
Brouillon → Inscriptions ouvertes → Inscriptions fermées → En cours → Terminée → Archivée → Purgée
```

Moodle est le seul à offrir une **durée d'inscription par apprenant** avec action à expiration (désinscrire **ou** maintenir inscrit avec accès bloqué et notes conservées). À reprendre.

### 2.3 Inscription — le standard universel

**Code de session de 6-8 caractères alphanumériques** (Google Classroom), + lien court partageable (WhatsApp/SMS), + import CSV pour les groupes constitués, + option « demande à valider » (Adobe Connect) quand l'accès est contingenté. Chez les concurrents, **CSV et SSO sont derrière un mur à 82-299 $/mois** alors que c'est vital pour un groupe de 15-30 personnes.

### 2.4 Faible bande passante — critère décisif, pas une option

Un forfait 2 Go/mois coûte **~2 % du RNB mensuel par habitant en Côte d'Ivoire** ; le Burkina Faso est parmi les plus chers de la région.

| Levier | Référence |
|---|---|
| **Présentation côté serveur** (diapo envoyée en image, pas en flux vidéo) | BBB — un ordre de grandeur d'économie vs partage d'écran |
| **Listen-only ~40 kbps** (audio mixé serveur, un seul flux descendant) | BBB — survit aux liens 2G/3G saturés et aux NAT hostiles |
| **Priorisation audio + écran AVANT la vidéo** | Zoom — 60-80 kbps en audio seul |
| **Politique d'espace « audio only »** imposée par l'admin, pas choix utilisateur | Google Workspace |
| **Hors-ligne réel** (SQLite local, rendus de devoirs mis en file) | App Moodle |
| **Notifications SMS** | Moodle 5.x — canal qui ne consomme pas de data |

⇒ **L'audio + la diapositive sont le canal pédagogique principal. La webcam est une option opt-in.**

### 2.5 Deux garde-fous issus du marché

- **Analytique d'engagement : comportementale, jamais biométrique.** Zoom a supprimé l'*attention tracking* en avril 2020 sous la pression ; la littérature CHI 2024 documente le rejet massif. Le modèle acceptable est celui de BBB : temps de parole, chat, mains levées, réponses aux sondages → score d'activité visible du seul formateur. **Aucune analyse de webcam.**
- **Relecture asynchrone interactive** — l'absent ne doit pas seulement *regarder* la rediffusion, il doit pouvoir répondre aux sondages et être compté comme ayant participé. C'est à la fois le différenciant le plus cité des comparatifs 2026 **et** la réponse la plus directe au problème de bande passante.

### 2.6 Conformité — ce qui contraint le schéma

- **Enregistrement vidéo d'apprenants : base légale = consentement**, préalable, écrit, **révocable et granulaire** (capture / diffusion au groupe / réutilisation sur une autre session). Représentant légal si mineur. ⇒ table `consents` **append-only**, jamais un booléen sur `users`.
  **Recommandation par défaut : enregistrer la vue formateur + le partage d'écran uniquement**, sans vignettes ni micros apprenants. C'est la seule configuration conforme sans gestion de consentement par personne.
- **Effacement (EDPB, rapport du 10 février 2026)** : doit être « verifiable and irreversible ». Un `is_deleted = true` avec données encore lisibles **n'est pas un effacement**. Défaut n°1 relevé : les **sauvegardes**, d'où le crypto-shredding (clé par tenant, on détruit la clé).
- **Durée** : CNIL retient **5 ans après la fin de la formation** pour la gestion de formation. Les replays pédagogiques relèvent de « durée liée à la finalité » — recommandation : durée de session + 30 à 90 j.
- **Côte d'Ivoire** : loi 2013-450, autorité = **ARTCI** (pas d'autorité dédiée distincte — `autoritedeprotection.ci` est son portail). **Déclaration préalable** avant mise en production ; **autorisation préalable pour tout transfert hors du pays** ⇒ **héberger en Europe est un transfert international**. Étude nationale de conformité en cours (janv.-mai 2026) : le contrôle se durcit.
- **Burkina Faso** : loi 001-2021, autorité = **CIL**. Sanction explicite pour « conservation au-delà de la durée **déclarée** » ⇒ **ne déclarer que des durées que le code sait appliquer**.
- Vous êtes **sous-traitant** du formateur (responsable de traitement). Le contrat doit fixer **par défaut** la suppression après grâce — sans cette clause, « on n'est pas obligé de garder » est vrai, mais **le droit de supprimer n'est pas établi non plus**.

---

## 3. Découvertes dans le code — à traiter indépendamment du chantier

Vérifiées en lisant le code. Les trois premières sont des **défauts actifs aujourd'hui**, pas des conséquences du projet.

| # | Découverte | Preuve | Gravité |
|---|---|---|---|
| **B1** | **Le job d'archivage détruit toute session longue.** `ArchiveOldSeances` archive sur `created_at < now()-2 semaines`, **sans filtre de source**. Un formateur qui programme sa session de 5 mois en janvier voit **toutes ses séances de mars-juin archivées mi-janvier**. Le commentaire justifiant ce critère est périmé : `date_seance` existe depuis `2025_11_25_165503`. | [app/Jobs/ArchiveOldSeances.php:58-60](app/Jobs/ArchiveOldSeances.php) | **Bloquant.** Mord déjà en mode KLASSCI sur toute séance programmée à plus de 14 j. |
| **B2** | **Le test amiral d'isolation ne prouve rien.** `MultiTenantIsolationFlowTest` (classé CRITICAL #1 dans les priorités) utilise `Sanctum::actingAs` → pas de bearer → `ResolveInstitution` ne s'exécute pas → `BelongsToInstitution` **saute son scope** (fail-open documenté). Ses tests passent grâce aux filtres explicites des contrôleurs, **pas** grâce au scope. | [tests/Feature/E2E/MultiTenantIsolationFlowTest.php:108,185,220](tests/Feature/E2E/MultiTenantIsolationFlowTest.php) | **Bloquant.** Toute promesse « zéro régression » est aujourd'hui **non falsifiable**. |
| **B3** | **`password_reset_tokens.email` est PRIMARY KEY**, alors que `users` a `unique(email, institution_id)`. Deux établissements partageant un email → collision de PK. | [0001_01_01_000000_create_users_table.php:25-29](database/migrations/0001_01_01_000000_create_users_table.php) | Table **inutilisable** en l'état dès qu'on active le reset. |
| **B4** | **Le doublon d'inscription, c'est deux vérités concurrentes selon l'endpoint** — pas « une table lue, une ignorée ». `user_classes` : `StudentClasseResolver:39`, `SeanceVisioEnricher:228`, `SeanceRecordingAccessService:66`. `classe_etudiant` : `VisioNotificationDispatcher:154,188`, `VideoSessionAttendancesSyncer:252`. **Un étudiant inscrit localement reçoit les notifications visio mais ne voit pas ses cours.** | ci-dessus | Élevée |
| **B5** | **Mur fonctionnel sur l'évaluation.** `ChecksEvaluationOwnership` est fail-closed sur `klassci_enseignant_id === null` : un formateur autonome pourra **créer** une évaluation mais jamais la publier, modifier ni supprimer (403). | [ChecksEvaluationOwnership.php:99-102](app/Http/Requests/Concerns/ChecksEvaluationOwnership.php) | À traiter frontalement, **jamais en assouplissant** l'invariant #119 |
| **B6** | **~20 sites adressent les séances par `klassci_seance_id`, pas par `id`.** Une séance locale serait **injoignable par toute la surface visio**. | `SeanceVisioEnricher:51,115`, `LocalSeanceLookup:64`, `VisioActivationService:230`, `DeleteSeanceRequest:44`… | Élevée |
| **B7** | `PurgeSoftDeletedInstitutions` ne teste que **4 relations sur 30**, alors que des FK `RESTRICT` existent sur les 30 tables de `config/tenancy.php`. **Toute purge réelle échoue.** | [PurgeSoftDeletedInstitutions.php:75-79](app/Console/Commands/PurgeSoftDeletedInstitutions.php) | Élevée — bloque le cycle de vie |
| **B8** | `orphan_row_archive` stocke la **ligne intégrale** ⇒ **ne peut pas servir de journal de purge RGPD** (ce serait recréer la donnée effacée). Journal distinct obligatoire. | [2026_08_23_100000:39-41](database/migrations/2026_08_23_100000_create_orphan_row_archive_table.php) | À savoir |

### Corrections à mes constats initiaux

- ✅ **`lessons.matiere_id` / `classe_id` pointent bien sur les id LOCAUX.** Le commentaire de migration « ID KLASSCI » est **faux et périmé** ; `Lesson::matiere()` fait `belongsTo(Matiere::class)`. **Toute la pile contenu est réutilisable sans modification.** C'est la meilleure nouvelle du dossier.
- ✅ **Les uniques `klassci_id` sont déjà composites** `(klassci_id, institution_id)`. SQL tolère N `NULL` dans un unique ⇒ rendre `klassci_id` nullable **n'affaiblit rien** et ne demande aucune colonne discriminante. Gratuit.
- ✅ `KlassciTenantDiscovery` filtre **déjà** `whereNotNull('klassci_api_url')` — la nullabilité est déjà sûre côté découverte.
- ⚠️ **Deux verrous que j'avais manqués** : `evaluations.klassci_matiere_id` **et** `klassci_classe_id` sont **NOT NULL** ; `chapters.matiere_id` est **NOT NULL**.
- ⚠️ **La table `sessions` est prise** (driver de session Laravel, `SESSION_DRIVER=database` en CI). Le nom est interdit.

---

## 4. Architecture cible — une seule solution

> **Le mode est une propriété de l'`Institution`. Le cycle de vie vit dans une table dédiée `training_sessions` liée 1—1 à une `classe`, qui reste l'audience universelle. Les liaisons locales sont portées par des colonnes FK ajoutées À CÔTÉ des colonnes `klassci_*`, jamais en rangeant des id locaux dedans.**

### 4.1 Le mode

`institutions.mode` — enum backed `klassci` | `standalone`, **NOT NULL default `klassci`**. Aucun backfill, aucune ligne existante ne change de comportement.

**Ne jamais dériver le mode de `klassci_api_url IS NULL`** : une URL vidée par erreur ferait basculer un tenant de production en silence.

Kill-switch en 3 points seulement : `EnsureKlassciSync` (court-circuit en tête), `KlassciTenantDiscovery::loadActiveTenants()` (filtre SQL), `LoginOrchestrator::attemptKlassci()` (sinon un mot de passe erroné renvoie **503** au lieu de 401 quand la seule institution KLASSCI du parc est injoignable).

### 4.2 Les deux tables nouvelles

- **`programs`** — le contenu **réutilisable**, jamais daté, jamais d'inscrits.
- **`training_sessions`** — l'**entité racine** : dates, état, code d'inscription, politique d'inscription, capacité, rétention, purge, `duplicated_from_id` + `schedule_shift_days`.

Nommage **anglais** : la convention mesurée du dépôt réserve le français aux entités importées de KLASSCI (`matieres`, `classes`, `seances`) et l'anglais au natif LMS (`lessons`, `chapters`, `quizzes`, `notifications`). Le préfixe `training_` désambiguïse contre `seances` — invariant à documenter partout : **`seance` = une réunion live ; `training_session` = le parcours daté qui les contient.**

### 4.3 Le point clé : `training_sessions.classe_id` en 1—1

Une session autonome **matérialise exactement une `classe` locale**. `TrainingSession` porte le cycle de vie ; `Classe` reste le point de jointure « audience » de tout le graphe.

**C'est un choix arithmétique, pas esthétique.** Sans cette projection, il faudrait une branche parallèle `training_session_id` sur **9 chemins de lecture existants** : `StudentClasseResolver:37-60`, `ChapterReadGate:71`, `VisioNotificationDispatcher:154,188`, `VideoSessionAttendancesSyncer:252`, `ClasseEtudiantsQueryService`, `quizzes.classe_id`, `evaluations.klassci_classe_id`, `seances` + 3 replis, `forum_topics.classe_id`. **9 doubles branches permanentes contre 0.**

Symétriquement, ne **pas** mettre ces colonnes directement sur `classes` : 15 colonnes de cycle de vie feraient exploser le modèle au-delà de la garde des 150 lignes, et mélangeraient un miroir KLASSCI read-only avec un objet de gestion.

`unique(institution_id, classe_id)` avec `classe_id` nullable : les sessions en brouillon coexistent, et **une classe ne peut jamais être revendiquée par deux sessions**.

### 4.4 La règle de non-destruction

**Ne jamais ranger un id local dans une colonne `klassci_*`.** Les réconciliateurs filtrent sur la présence de la clé (`StaleSeanceArchiver:46`, `CleanObsoleteSeances:89,182`, `TeacherCursorStream:78`). Une séance locale portant un faux `klassci_seance_id` entrerait dans le périmètre de réconciliation et serait **archivée en masse au premier cycle de 5 minutes**. Laisser ces colonnes `NULL` rend le pipeline de sync **inerte en mode autonome, gratuitement**.

### 4.5 La duplication — raison d'être du couple `Program`/`TrainingSession`

Le contenu est accroché au **`Program`**, jamais à la session ⇒ **dupliquer une session ne duplique aucun contenu**. `POST /training-sessions/{id}/duplicate { starts_on }` calcule un décalage, copie la session et ses séances en décalant les dates, remet à zéro tout l'état visio, et **ne copie pas** inscriptions, progressions, tentatives, présences, enregistrements, forum, consentements.

### 4.6 Inscription unifiée

`classe_etudiant` devient **la seule vérité** (FK locales, unique effectif posé par `2026_08_23_100003`). `user_classes` redevient ce que son nom dit : un cache de sync KLASSCI, **plus jamais lu directement**. Un `EnrollmentRepository` migre les 4 lecteurs, avec repli sur `user_classes` tant que le backfill d'un tenant KLASSCI n'a pas tourné.

**Ne pas supprimer `user_classes`** dans ce chantier : elle est écrite à chaque login KLASSCI. Suppression après période d'observation.

---

## 5. Séquencement

Chaque lot : déployable seul, réversible, valeur observable.

### Préalable — les 3 correctifs (décision P3)

| PR | Contenu | Taille | Pourquoi cet ordre |
|---|---|---|---|
| **C1** | **B2** — réparer le test amiral d'isolation : bearer réel, helper `ActsAsTenantUser` factorisé (le motif existe, dupliqué dans ~20 fichiers), test-grep anti-`actingAs` sur le patron des gardes existantes | S | **Sans lui, aucune des deux PRs suivantes n'est vérifiable.** Zéro fichier `app/`. |
| **C2** | **B1** — archivage sur `date_seance` (colonne existante depuis `2025_11_25_165503`) avec repli `created_at`. Test de garde figeant le comportement actuel **avant** le changement, puis inversé. | S | Bug de production actif. Change un comportement existant → runbook. |
| **C3** | **B3** — clé de `password_reset_tokens` en `(email, institution_id)`, ou table dédiée | S | Bloquant du lot 2, sans valeur seul mais sans risque |

### Le chantier

| Lot | Objectif | Taille | Ce qui domine le coût |
|---|---|---|---|
| **0** | **Filet de non-régression** — golden master de schéma (9 tables, 3 jambes CI), inertie des réconciliateurs, harnais 3 institutions | S | Arbitrer les statuts qui changent (403↔404). **Zéro code applicatif.** |
| **1** | **L'institution autonome existe** — `mode`, `klassci_api_url` nullable, kill-switch 3 points | M | L'audit « zéro appel HTTP sortant », pas la migration |
| **2** | **Le compte formateur** — B3, invitations, reset password, désambiguïsation du login local | L | La sécurité multi-tenant et les tests A/B |
| **3** | **`programs` + `training_sessions`** — cycle de vie, code d'inscription, duplication | L | Les `->change()` sur colonnes indexées, validés sur les 3 jambes CI |
| **4** | **Inscriptions unifiées** — B4 | M | Le backfill idempotent + golden master avant/après |
| **5** | **Séances locales et visio** — B6, `SeanceLocator` par mode, CRUD séance | L | Le découplage de l'activation visio |
| **6** | **Listings locaux** — 6 endpoints, stratégie par mode | M | 6 golden masters de payload capturés **avant** refactor |
| **7** | **Affectation intervenants + import CSV** asynchrone | M | L'import borné à 55 s avec rapport ligne à ligne |
| **8** | **Évaluation autonome** — B5, colonnes locales | M | L'ownership dual sans assouplir l'invariant #119 |
| **9** | **Clôture, rétention, purge, export** — B7, B8, consentements | M/L | Le journal de purge et l'export machine-readable |

### Chantier parallèle — migration cPanel → Contabo

Indépendant des lots fonctionnels, mais **préalable au lot 5** : sans VPS, pas de BigBlueButton ni de worker permanent. Contenu : provisionnement, déploiement, TLS, sauvegardes, scheduler, Redis, puis installation BBB et bascule du fournisseur visio (le webhook `recording-ready` signé est déjà agnostique — c'est un adaptateur de payload, pas une refonte).

**Jalon démontrable après le lot 4** : tout le parcours contenu fonctionne en autonome **sans qu'une seule ligne de la pile leçons/chapitres/slides/quiz/progression/forum ait été modifiée**.

**Pourquoi le lot 0 en premier** : le lot 1 est le plus petit changement qui débloque *techniquement* le reste. Mais si on le livre en premier et qu'il casse l'isolation d'une école KLASSCI, **la CI reste verte** (B2). Le lot 0 coûte S, ne touche aucun fichier `app/`, et rend tous les lots suivants vérifiables.

---

## 6. Ce qu'il ne faut pas faire

1. **Ranger des id locaux dans les colonnes `klassci_*`** → archivage silencieux en masse au premier cycle. Le raccourci qui détruit les données d'un client.
2. **Dériver le mode de `klassci_api_url IS NULL`** → une faute de saisie devient un changement de mode en production.
3. **Ouvrir un signup public** → `/institutions/active` est un endpoint public ; un `POST /auth/register` en ferait la porte d'entrée d'inscriptions anonymes sur n'importe quel tenant, **y compris une école KLASSCI**. L'invitation ferme le sujet.
4. **Basculer `BelongsToInstitution` en fail-closed « pendant qu'on y est »** → option déjà instruite et **rejetée** (rayon ~57 services). Le fail-secure est porté par `ResolveInstitution`. Hors périmètre.
5. **Écrire un test d'isolation avec `Sanctum::actingAs`** → il passera, il ne prouvera rien.
6. **Dupliquer les contrôleurs par mode** → double la surface d'autorisation à auditer, et c'est là que naissent les fuites cross-tenant. Le mode se résout **une fois**, au niveau de la stratégie de fetch.
7. **Étendre `matiere_enseignant`** (ids KLASSCI, méthodes statiques) → le triplet existe déjà dans `classe_matiere.enseignant_id`, avec FK locale.
8. **Ajouter `mode` au payload de `/institutions/active`** → casse le golden master public et **expose l'architecture interne de chaque client**.
9. **Import CSV synchrone** → même avec un worker permanent, un import de plusieurs centaines de lignes doit rester asynchrone, idempotent et chunké (rapport d'erreurs ligne à ligne).
10. **Une PR big-bang** → branche protégée + revue bloquante ; impossible de dire quel changement a cassé quelle école.
11. **Analyse de webcam / attention tracking** → risque réputationnel documenté, sans contrepartie.

---

## 7. Décisions actées

### P1 — Hébergement : **VPS Contabo** (décidé)

Cela lève la contrainte structurante de [docs/VISIO_RECORDING_CPANEL_DECISION.md](docs/VISIO_RECORDING_CPANEL_DECISION.md) et débloque quatre choses :

| Débloqué | Conséquence |
|---|---|
| **BigBlueButton auto-hébergé** | Présentation servie côté serveur + **listen-only ~40 kbps** + breakout, tableau blanc, sondages natifs. La meilleure réponse à la bande passante ouest-africaine. |
| **Workers permanents** | Fin du drain 55 s. Import CSV, conversion de fichiers, purges et exports deviennent des jobs normaux. |
| **Redis** | Cache et files réels. `TenantScopedCache` a déjà une jambe Redis testée en CI (#374). |
| **Enregistrement fiable** | Post-traitement asynchrone BBB, différable la nuit — au lieu du modèle Jibri « une machine par enregistrement simultané ». |

**⚠️ Ces deux alertes ont été révisées au second tour de recherche — voir §9.1 et §9.2. Contabo est disqualifié pour le nœud visio, et le périmètre réglementaire que j'avais énoncé était faux.**

La migration cPanel → Contabo est **un chantier en soi** (déploiement, données, scheduler, TLS, sauvegardes), à mener en parallèle et non dans les lots fonctionnels.

### P2 — Modèle économique : **paiement à la session ouverte, espace gratuit au repos**

Une seule solution, justifiée :

| Élément | Règle |
|---|---|
| Créer un espace | **Gratuit**, toujours |
| Espace au repos (aucune session active) | **Gratuit**, contenu conservé, lecture seule. Le formateur ne peut simplement pas ouvrir de nouvelle session. |
| Ouvrir une session | **Payant** — palier de nombre d'apprenants × durée |
| Archive et export de fin de session | **Gratuits** |

**Pourquoi ce modèle et pas l'abonnement.** L'abonnement mensuel est le modèle dominant (29-299 $/mois) et il est **structurellement inadapté** au scénario décrit : un formateur qui anime deux sessions par an paie douze mois pour huit d'usage. Le marché documente exactement ce qui arrive alors — il annule, et **perd ses données** (Kajabi : site inaccessible immédiatement, suppression possible à 90 j ; Thinkific : rétention non garantie). C'est le premier motif de défiance relevé dans les comparatifs.

Ici le formateur **n'a jamais besoin d'annuler**. Il cesse simplement d'ouvrir des sessions. Sa dette envers la plateforme tombe à zéro, son contenu reste.

**La conséquence technique est décisive** : le cycle de vie d'un espace n'est **jamais piloté par un impayé**, uniquement par les dates de session et l'inactivité. Pas de machine à états de facturation entremêlée avec la machine à états produit — deux fois moins de chemins à auditer, et une purge dont le déclencheur est une date, pas un événement de paiement.

**Moyens de paiement** : mobile money en premier (Orange Money, Wave, MTN MoMo, Moov), carte en secours. La carte bancaire est minoritaire sur la cible ; en faire le canal principal élimine une partie des formateurs.

*Ce qui invaliderait ce choix* : si l'usage réel devenait continu (des sessions qui s'enchaînent sans interruption toute l'année), l'abonnement redeviendrait plus simple pour le client comme pour nous. À réévaluer sur données d'usage réelles, pas par anticipation.

### P3 — Défauts B1/B2/B3 : **corrigés tout de suite, en PRs séparées** (décidé)

Ordre imposé par la dépendance logique : **B2 d'abord** (sans lui, aucune correction n'est vérifiable), puis B1 (bug de production actif), puis B3 (bloquant du lot 2).

---

## 8. Vérification

Quand l'implémentation démarrera, chaque lot se vérifie ainsi :

- **Non-régression KLASSCI** : trois institutions dans le harnais — `A (klassci)`, `B (klassci)`, `C (standalone)`. Le triangle A/B prouve l'isolation historique, A/C prouve la cohabitation. **Data provider de mode**, pas deux suites : chaque test métier tourne dans les deux modes et assert le même contrat.
- **Bearer réel obligatoire** sur tout test d'isolation (`createToken()->plainTextToken` + `withToken()`), jamais `Sanctum::actingAs`. Verrouillé par un test-grep, sur le patron des gardes existantes (`CiAppKeyNotCommittedTest`).
- **Zéro appel sortant** en mode autonome : `Http::fake()` + assertion « 0 requête » sur le parcours complet.
- **Inertie des réconciliateurs** : test prouvant que `SyncKlassciSeances`, `CleanObsoleteSeances` et `StaleSeanceArchiver` ne touchent aucune ligne `klassci_* IS NULL`.
- **Golden master de schéma** sur les 9 tables touchées (index uniques + nullabilité), rejoué sur les **3 jambes CI** — `->change()` sur SQLite reconstruit la table et peut perdre un index **silencieusement**.
- **Golden master de payload** capturé avant tout refactor des listings.
- **Budget PHPStan** : baseline de 1958 lignes (326 entrées), niveau 9 ; modifier un fichier de la baseline oblige à corriger ses violations **dans la même PR**. Voir §9.6 pour le décompte réel — bien moins coûteux que prévu sur les modèles, très coûteux sur certains services.

---

# 9. Second tour de recherche — corrections et découvertes

Mené le 2026-08-29 après validation du plan, sur les zones où je m'étais appuyé sur des sources non recoupées ou que j'avais laissées de côté. **Six corrections changent des décisions.**

## 9.1 CORRECTION MAJEURE — le périmètre réglementaire n'est pas « hors Côte d'Ivoire »

J'avais écrit que sortir les données de Côte d'Ivoire déclenche l'autorisation ARTCI. **Le critère réel est « hors espace CEDEAO »** — le formulaire officiel s'intitule littéralement *« Demande de transfert de données à caractère personnel hors espace CEDEAO »* ([autoritedeprotection.ci/documents](https://www.autoritedeprotection.ci/documents/)).

**Le piège que cela révèle : le Burkina Faso n'est plus dans la CEDEAO.** Retrait effectif du BF, du Mali et du Niger le **29 janvier 2025**. Donc **tout flux de données Côte d'Ivoire → Burkina Faso est lui-même un transfert « hors CEDEAO »**, soumis à formalité ARTCI — **Europe ou pas**. Une cible bi-pays CI+BF crée l'obligation à elle seule.

Deux conséquences que mon plan ratait :
- **Une porte de sortie existe** : héberger au **Sénégal, Ghana, Nigeria ou Bénin** (toujours CEDEAO) ne déclencherait pas la formalité.
- **Côté Burkina**, la loi 001-2021 impose en plus une **clause contractuelle de réversibilité des données** avec l'hébergeur — que les CGV standard d'un hébergeur bon marché n'offrent pas.

Régime dual confirmé par l'existence de trois formulaires distincts : déclaration normale, autorisation préalable, transfert hors CEDEAO. Pour un SaaS éducatif, c'est **déclaration + demande de transfert cumulées**. Aucune liste ivoirienne de pays adéquats n'existe : **le RGPD allemand est un argument au soutien du dossier, pas une dispense**.

*Non vérifié, à instruire* : contenu de la décision ARTCI 2025-1332 (PDF inaccessible), coût et délai de la procédure, et surtout **si les données de mineurs et d'évaluation basculent en régime d'autorisation plutôt que de déclaration**.

## 9.2 CORRECTION MAJEURE — Contabo est disqualifié pour BigBlueButton

Pas une nuance de gamme : deux échecs durs.

- **Steal time CPU de 10–30 % en heures de pointe** sur les VPS Contabo (vCPU partagés), pics au-delà de 40 %. La doc BBB est explicite : *« avoid general purpose VMs as they have higher steal time and are not ideal for FreeSWITCH which is mixing audio in real-time »*. Un steal de 20 % sur FreeSWITCH ne ralentit pas le service — **il hache l'audio**.
- **Bande passante** : le VPS d'entrée plafonne à **200 Mbit/s**, sous les 250 Mbit/s symétriques requis.

Recommandation officielle BBB : **Hetzner AX52 (dédié)** ou **CCX33 (CPU dédié)**. Si l'on tient à Contabo, uniquement la gamme **VDS** (cœurs AMD EPYC dédiés) — et **pas le VDS S**, dont les 3 cœurs sont sous les 8 requis.

Contabo reste défendable pour l'application LMS et la base, mais les rapports convergents de pics de `fsync > 50 ms` sous charge le déconseillent pour une base de production.

## 9.3 DÉCOUVERTE — héberger en Côte d'Ivoire est réellement possible

Angle mort de ma première étude. L'Afrique de l'Ouest a rattrapé son retard en **datacenters**, pas en **cloud self-service** — mais des offres existent :

- **ST Digital / CloudStore.africa** — datacenter Tier III à **Grand-Bassam (VITIB, ~30 km d'Abidjan)**, serveurs virtuels **en self-service**, facturation en FCFA, **~10 ms de latence** depuis Abidjan contre ~150 ms vers Francfort.
- **Raxio CIV1** (Grand-Bassam) — Tier III certifié Uptime, 800 racks, 3 MW, carrier-neutral, et surtout **héberge le CIVIX**, le point d'échange internet ivoirien : peering local, transit réduit.
- **Orange Business CI** — VPS haute disponibilité, tarifs sur devis uniquement.

⚠️ **Piège identifié** : *AFRICLOUD* commercialise du « VPS Côte d'Ivoire » mais héberge à **Lisbonne et Johannesburg**. C'est du marketing de latence, **pas de la résidence de données** — cela ne règle ni ARTCI ni CIL.

**Architecture à instruire — hybride :** application + base + **stockage des enregistrements** à Abidjan (conformité native, 10 ms), nœud BBB en Europe sur CPU dédié *si aucun devis local ne tient*. Le flux WebRTC est transitoire ; **les enregistrements ne le sont pas** — ils ne doivent pas résider en Europe.

## 9.4 CORRECTION DU MODÈLE ÉCONOMIQUE — le payeur n'est pas celui que je croyais

Tous les faits convergent **contre** le B2C individuel en Côte d'Ivoire :

- **Etudesk**, le leader ivoirien, est **fondamentalement B2B** et financé par un **fonds d'impact éducatif**, pas par du capital de croissance.
- Le plus gros contrat identifié dans la région est un **marché bailleur** : Sayna–SIFA/GIZ, **1,6 M€** pour le Togo.
- **ALX**, le plus gros volume du continent, est **gratuit** — financé par la Mastercard Foundation.
- **AltSchool Africa**, le pari B2C le plus agressif, s'est **fissuré publiquement** en octobre 2025 (étudiants débités sans accès à la plateforme, notes incohérentes) et a **gelé son expansion**.

**Et surtout, le FDFP.** Le Fonds de Développement de la Formation Professionnelle (loi n°91-997) collecte **1,6 % de la masse salariale** du privé ivoirien — 0,4 % apprentissage + 1,2 % formation continue — et la redistribue aux entreprises qui forment. Plus de **27 000 plans de formation financés** pour le seul secteur du commerce en 2025. **Condition impérative : la formation doit être dispensée par un organisme agréé par l'État.**

⇒ **Le payeur en Côte d'Ivoire n'est probablement ni l'apprenant ni le formateur, mais l'entreprise qui récupère son FDFP.** Cela change le produit : il faut du **reporting de présence et d'assiduité exportable**, des **attestations conformes**, une **facturation entreprise**, un **suivi par cohorte**. Et il faut vérifier tôt si la structure peut être agréée — sinon on est exclu du seul flux d'argent structuré du pays.

Le modèle « paiement à la session » reste juste, mais il doit être **doublé d'un canal B2B/institutionnel**, qui portera vraisemblablement le chiffre d'affaires.

## 9.5 RENFORCEMENT — le paiement à la session n'est pas un choix, c'est la seule option

Mon raisonnement était bon mais l'argument était faible. Le vrai argument : **le mandat de prélèvement récurrent mobile money n'existe pas de façon exploitable en zone UEMOA.** Le *pre-approval mandate* de MTN est expérimental et documenté comme défaillant ; aucun agrégateur UEMOA (CinetPay, PayDunya, FedaPay, Hub2) ne propose de tokenisation wallet + débit récurrent. **L'abonnement auto-débité n'est pas implémentable.**

Corrections à ma formulation :
- **« Carte en secours » est faux.** La carte n'est pas un fallback de disponibilité, c'est un **segment** : diaspora et clients corporate/ONG — qui peuvent porter le CA.
- **Il manque un troisième moyen : le virement bancaire UEMOA**, pour les gros paliers. Les plafonds mobile money (~1,5–2 M FCFA par transaction, et surtout le **plafond de solde du wallet**) bloquent les tickets élevés.
- Rail recommandé : **CinetPay** (SDK PHP officiel, SDK Laravel communautaire, sandbox, couverture CI+BF en une intégration) + **Wave en direct** (~1 % contre ~2 %) pour les paiements Wave. Interface `PaymentGateway` avec implémentations dès le jour 1.
- **Le job de polling de réconciliation est une fonctionnalité de niveau 1, pas du durcissement ultérieur.** En CI/BF, le webhook manquant est le quotidien, pas un cas limite.
- Une **société ivoirienne** (RCCM + compte bancaire UEMOA) est requise pour encaisser.

**Contrainte que j'avais totalement manquée — la FNE.** La **Facture Normalisée Électronique est obligatoire en Côte d'Ivoire depuis le 1er décembre 2025**, pour toutes les entreprises sans exception de régime. **Seule une FNE conforme justifie une charge ou permet la déduction de TVA** : un client entreprise ne pourra pas déduire notre service sans elle. Ce n'est pas un PDF à générer, c'est une **intégration à la plateforme DGI** — un chantier à part entière à budgéter. TVA CI 18 %. Côté Burkina, TVA 18 % sur les services numériques depuis janvier 2025, avec obligation pour l'opérateur de plateforme de collecter et reverser **pour le compte des fournisseurs tiers**, et retenue à la source passant de 20 % à 30 % en 2026.

## 9.6 Vérifications dans le dépôt

| Point | Verdict |
|---|---|
| **Risque `->change()` sur SQLite** | **INFONDÉ — je m'étais trompé.** Laravel 12.62 : `BlueprintState` relit le schéma réel (`pragma_index_list`, `pragma_foreign_key_list`) et **préserve index uniques composites et FK** lors du rebuild. Précédent dans le dépôt : `2025_10_19_202208` a déjà fait un `->change()` sur `email`, membre d'un unique composite. Suivre le patron de `2026_08_23_100003` (nouvel index d'abord, garde `hasIndex`, tout dans un seul `Schema::table`). |
| **Baseline PHPStan** | **Coût quasi nul sur le périmètre prévu : 1 seule entrée** sur les 11 fichiers ciblés (`EnsureKlassciSync.php`, triviale). **Mais la dette est concentrée dans les services** : `SeanceDetailQueryService` **16**, `StudentGradesAggregator` **14**, `VisioParticipantsListService` **13**, `StudentDashboardService` **13**, `SeanceVisioEnricher` **11**, `VisioToggleService` **11**. ⇒ **C'est le critère de découpage des PRs.** |
| **`app/Models/User.php` est à 149 lignes sur 150** | **Saturé.** Toute addition — une relation, un cast, même un bloc PHPDoc — fait échouer le garde. Extraction vers `app/Models/Concerns/` obligatoire avant d'y toucher (patron : `InteractsWithRoles`). `Evaluation.php` 138 et `Seance.php` 134 sont à surveiller. |
| **Contrat frontend** | **Trois enveloppes distinctes coexistent** : `data` = tableau (`my-teaching`), `data` = objet à clés (`classes/{id}`), `data` + `meta` sœur (`upcoming`), plus `data.etudiants` d'un niveau supplémentaire. Le front lit **deux formes en parallèle** pour chaque relation (`matiere.nom` **ou** `matiere_nom`) — c'est une dette, pas un contrat. **Bug latent trouvé** : `useClasseDetails.js:99` attend `data.seances` sur `/upcoming`, ce qui renvoie toujours `[]`. Ne pas « corriger » côté backend, cela casserait les deux autres consommateurs. |

## 9.7 Écosystème — ce qu'il ne faut pas réécrire

- **BigBlueButton** : SDK PHP **officiel** `bigbluebutton/bigbluebutton-api-php` **v3.0.0 du 19 août 2026**, PHP ≥ 8.2, maintenu, 1,33 M installations. Pas de package Laravel officiel — l'enregistrement dans le conteneur tient en ~10 lignes. `bbb-webhooks` est une app Node séparée, dernière release mai 2025 : maintenance ralentie, à surveiller.
- **LTI 1.3** : `packbackbooks/lti-1p3-tool` **v6.4.3, mars 2026**, maintenu, 467 k installations, aucune alerte. **Sans réserve — rien à écrire.**
- **xAPI — le point faible.** `TinCanPHP` est figé en v1.0.0 (PHP 5.4). `php-xapi/*` est plus crédible pour du neuf. **Il n'existe pas de LRS PHP mature : ne pas écrire de LRS.** Soit émettre vers un LRS externe, soit se limiter à une table `xapi_statements` + `POST/GET /statements`.
- **SCORM 1.2** : résolu côté JS avec `jcputney/scorm-again`. Côté PHP il n'y a rien à réutiliser, mais il n'y a que ~200 lignes à écrire (dézip, parse `imsmanifest.xml`, deux endpoints CMI).
- **Multi-tenant : garder le socle maison, ne pas migrer.** `stancl/tenancy` apporte du **multi-base** dont le dépôt n'a pas besoin ; `spatie/laravel-multitenancy` en single-database fournit littéralement ce que le dépôt possède déjà, en moins documenté. Une migration serait un **coût pur** pour une surface fonctionnelle identique.

## 9.8 Terrain — trois faits qui doivent infléchir le produit

- **L'électricité, pas le réseau, est le premier obstacle.** Étude peer-reviewed (Nigeria rural) : coût **88,2 %**, **électricité 85,3 %**, contraintes financières 84,1 %. Afrobaromètre mesure **32 points** d'écart de littératie numérique selon la présence d'électricité. ⇒ **une session 100 % synchrone sur 4-6 mois se heurte frontalement à ces deux facteurs.** Le direct doit être **systématiquement rattrapable en dégradé** — audio seul, transcription, résumé texte. Une coupure de courant ne doit pas produire un abandon.
- **WhatsApp n'est pas une intégration optionnelle.** Travaux MIT et études peer-reviewed documentent des enseignements **entièrement conduits sur WhatsApp** en Afrique subsaharienne, précisément parce qu'il échappe aux contraintes de connectivité. Le groupe WhatsApp de cohorte **sera** le lieu de vie de la promo, qu'on le prévoie ou non. La question est de l'orchestrer ou de le subir.
- **Le taux de complétion est le seul vrai différenciateur.** ~**5 %** de complétion pour les apprenants africains sur MOOC (contre 10–15 % mondial) ; **70–80 %** d'abandon estimé chez ALX. Moringa School vend explicitement sa complétion supérieure. C'est là que se gagne le marché — et c'est exactement ce que le format cohorte avec formateur peut délivrer, **à condition de tenir la charge opérationnelle** (leçon AltSchool).

## 9.9 Open Badges — argumentaire à corriger, décision inchangée

La fraude aux diplômes est **massive et judiciairement documentée** : 91 agents des douanes ivoiriennes déférés en mars 2025, **84 condamnés à 6 mois ferme** en juillet 2025 ; **21 agents publics burkinabè révoqués** en septembre 2025, 3 de plus en mai 2026.

**Mais mon mécanisme causal était faux.** Ces affaires portent sur des **diplômes d'État falsifiés** pour entrer dans la fonction publique — pas sur des certificats de formation continue privée. Un Open Badge émis par notre plateforme ne résout pas ce problème.

Ce que la fraude crée réellement, c'est un **contexte de défiance envers tout papier**. Le bon argumentaire est donc « **être crédible d'emblée sur un marché où le certificat papier ne vaut rien** », pas « lutter contre la fraude ».

Deux conséquences pratiques :
1. **La vérifiabilité cryptographique ne vaut rien sans autorité émettrice reconnue.** Un badge signé par un émetteur inconnu prouve seulement qu'un inconnu l'a signé. La valeur vient du couple **vérifiabilité × agrément FDFP/ministériel**. Il n'existe pas de RNCP ivoirien : la reconnaissance passe par l'agrément de l'organisme.
2. **QR code + page de vérification publique, pas wallet.** C'est le pattern qui fonctionne dans la région (e-Diplôme RDC, blockchain nationale lancée en 2025 ; KNEC Kenya a porté 30 M de dossiers on-chain). Un wallet de credentials à installer est un échec d'adoption annoncé au vu des données de littératie numérique.

## 9.10 Ce qui reste non vérifié

1. **Décision ARTCI n°2025-1332** — PDF inaccessible. Priorité n°1 à récupérer manuellement.
2. **Coût et délai de la procédure de transfert ARTCI** — ne rien chiffrer avant lecture de la décision 2016-0201 sur les frais de dossier.
3. **Régime applicable aux données de mineurs et d'évaluation** — déclaration ou autorisation ?
4. **Tarifs IaaS de ST Digital et Orange Business CI** — jamais publiés, devis obligatoire.
5. **Débit réseau contractuel des gammes Contabo VDS** — non documenté.
6. **Décrets d'application et formulaires CIL du Burkina Faso** — introuvables.
7. **Grille tarifaire réelle en FCFA** de la formation pro en ligne en CI/BF — à obtenir par appels, pas par recherche web.
8. **Procédure et délai d'agrément FDFP pour un organisme 100 % en ligne.**
9. **Événement BBB `rap-publish-ended`** — à confirmer sur la doc officielle avant d'y baser une conception.
10. Les données de performance Contabo proviennent de **sites de review**, pas de benchmarks exécutés. Convergence forte, mais prudence.

---

# 10. Confrontation au dépôt réel (2026-08-29)

Vérification de toutes les affirmations contre le code et l'état git. **Verdict : la recherche cadre, et le dépôt est plus avancé que je ne le pensais sur l'infrastructure.**

## 10.1 J'analysais un HEAD périmé — la migration hors cPanel est déjà engagée

Trois commits postérieurs à `35cd0da8` changent le cadre :

```
de49dd19  fix(docs): copie openapi.yaml vers storage pour Swagger UI
fc4527be  fix(http): trust Traefik proxies behind Dokploy
35cd0da8  chore(ops): image Docker Dokploy pour le LMS
```

Le dépôt contient déjà `Dockerfile.prod`, `docker-compose.prod.yml`, `docker/entrypoint.sh`, et un guide **[docs/DEPLOY_DOKPLOY.md](docs/DEPLOY_DOKPLOY.md)** intitulé littéralement **« Déploiement LMS sur Dokploy (Contabo) »**, calqué sur l'ADR-0024 du projet Wourri (Dokploy + Traefik + Swarm). Trois services prévus : `web`, `lms-worker` (`queue:work`), `lms-scheduler` (`schedule:work`).

⇒ **La contrainte « cPanel mutualisé, pas de worker permanent, drain 55 s » est déjà en cours de levée.** Le lot 5 et l'import CSV asynchrone n'ont plus le plafond que je leur prêtais.

**Le déploiement n'est pas encore actif** : `api.africandigitconsulting.com/up` → HTTP 000, DNS non résolu.

## 10.2 ⚠️ DÉFAUT B0 — le worker Dokploy ne consommera que la queue `default`

Découvert en confrontant l'entrypoint aux jobs réels. **Plus urgent que B1/B2/B3, et le correctif tient en une ligne.**

[docker/entrypoint.sh:21](docker/entrypoint.sh) :
```sh
exec php artisan queue:work database --sleep=3 --tries=3 --timeout=120 --max-time=3600
```
Le premier argument positionnel de `queue:work` est la **connexion**, pas la queue. Sans `--queue`, le worker consomme uniquement `default` (`config/queue.php:41`, `DB_QUEUE=default`).

Or les jobs utilisent explicitement trois queues :

| Queue | Jobs concernés |
|---|---|
| `low` | `ConvertChapterFile` (PPT/PDF → slides), `GenerateReportPdf`, **`ProcessSeanceRecordingReady`** (enregistrements visio), `SyncKlassciSeances`, `CleanObsoleteSeances` |
| `high` | notifications visio urgentes ([AsyncVisioNotificationDispatcher.php:39](app/Services/Notification/AsyncVisioNotificationDispatcher.php)) |
| `default` | notifications visio normales |

Aujourd'hui tout fonctionne **uniquement grâce à `queue:drain`**, qui lance `queue:work --queue=high,default,low` ([QueueDrainCommand.php:20-24](app/Console/Commands/Scheduler/QueueDrainCommand.php)).

**Le piège est à double détente sous Dokploy :**
- Si on **garde** le drain : `lms-scheduler` le déclenche chaque minute pendant que `lms-worker` tourne déjà → **deux consommateurs concurrents** sur la table `jobs`, contention inutile, et le worker dédié ne sert quasiment à rien.
- Si on **retire** le drain (le geste logique quand on a un worker permanent) : **conversion des slides, rapports PDF, traitement des enregistrements visio, sync des séances et notifications urgentes meurent silencieusement.**

**Correctif** : ajouter `--queue=high,default,low` à l'entrypoint, puis conditionner le drain (le désactiver quand un worker dédié tourne). À faire **avant** le premier déploiement Dokploy.

## 10.3 Documentation devenue fausse

- [docs/VISIO_RECORDING_CPANEL_DECISION.md:8-9](docs/VISIO_RECORDING_CPANEL_DECISION.md) affirme encore : *« La production reste sur un hébergement cPanel mutualisé. Le backend LMS ne doit donc pas dépendre d'un serveur Jitsi auto-hébergé, de Jibri, de Supervisor… »* — contredit par `DEPLOY_DOKPLOY.md`.
- [routes/console.php:149-151](routes/console.php) : *« Worker de queue pour mutualisé cPanel (#369) — pas de Supervisor disponible, donc pas de démon `queue:work` permanent »* — faux sous Dokploy.

## 10.4 Toutes mes affirmations sont confirmées au caractère près

| Affirmation | Vérification |
|---|---|
| **B1** archivage sur `created_at` | ✅ [ArchiveOldSeances.php:58-60](app/Jobs/ArchiveOldSeances.php) — avec le commentaire périmé *« la programmation n'étant pas stockée localement »* |
| **B2** `Sanctum::actingAs` dans le test amiral | ✅ lignes **108, 185, 220** exactement |
| **B3** `password_reset_tokens.email` en PK | ✅ `$table->string('email')->primary();` |
| Table `sessions` prise | ✅ créée juste après, dans la même migration |
| `institutions.klassci_api_url` NOT NULL | ✅ `$table->string('klassci_api_url', 500);` sans `->nullable()` |
| `User.php` à 149/150 lignes | ✅ 149 · `Evaluation.php` 138 · `Seance.php` 134 |

## 10.5 L'issue #469 est la seule ouverte — et elle pointe déjà vers le chantier

**Une seule issue ouverte sur tout le dépôt** : *« feat(visio) : finaliser le parcours Jitsi/Jibri vers la formation »*. Elle décrit exactement le blocage identifié : *« Le parcours complet reste bloqué par l'absence d'un serveur Jitsi/Jibri ou d'un fournisseur d'enregistrement. »*

Son critère de fermeture emploie déjà le vocabulaire du chantier : *« créer le chapitre dans **la formation** de l'enseignant »*.

⇒ **#469 se débloque mécaniquement avec le VPS Contabo.** Elle devient le point d'entrée naturel du lot 5.

## 10.6 Le vocabulaire est vierge

`grep -rn "formation"` sur `app/`, `routes/`, `database/migrations/` → **zéro occurrence**. Les noms `programs` et `training_sessions` n'entrent en collision avec rien. Aucune des 25 specs de `.claude/specs/` ne traite d'autonomie ou de découplage — confirmation du constat initial.

## 10.7 Point à corriger dans mon §9.1

Le guide Dokploy fixe `QUEUE_CONNECTION=database` et `CACHE_STORE=database`. **Redis n'est pas prévu** dans la configuration cible. Mon « Redis débloqué » est une *possibilité* offerte par le VPS, pas l'état configuré. Conséquence concrète : `TenantScopedCache` restera sur la voie « préfixe de clé + purge par `LIKE` », sans tags — comportement déjà testé en CI, mais à ne pas confondre avec la jambe Redis.

## 10.8 Séquencement révisé

Le préalable devient **quatre** PRs, et B0 passe en tête :

| PR | Contenu | Pourquoi ce rang |
|---|---|---|
| **C0** | **B0** — `--queue=high,default,low` dans l'entrypoint + conditionner `queue:drain` | **Avant le premier déploiement Dokploy**, sinon cinq familles de jobs meurent en silence |
| **C1** | **B2** — réparer le test amiral d'isolation | Sans lui, rien n'est vérifiable |
| **C2** | **B1** — archivage sur `date_seance` | Bug de production actif |
| **C3** | **B3** — clé `password_reset_tokens` | Bloquant du lot 2 |

Et deux mises à jour documentaires à joindre : `VISIO_RECORDING_CPANEL_DECISION.md` et le commentaire de `routes/console.php`.

## 10.9 Décision utilisateur : **cPanel est abandonné, Contabo est la cible**

Confirmé le 2026-08-29. Toute contrainte « hébergement mutualisé » disparaît du plan.

### Le serveur réel — specs vérifiées dans l'ADR-0024 de Wourri

`serveur.africandigitconsulting.com`, VPS Contabo `vmi3499821` :
**Ubuntu 24.04 LTS · 8 vCPU AMD EPYC · 23 Go RAM · 4 Go swap · 290 Go disque (13 utilisés) · Docker Swarm + Dokploy + Traefik/Let's Encrypt · Allemagne (IPv6 `2a02:c207`).**

### ⚠️ Conséquence dure : BigBlueButton ne peut pas aller sur ce serveur

Les 8 vCPU / 23 Go correspondent **exactement** aux minima BBB — mais **pour BBB seul**. Or la machine héberge déjà `wourri` (dont l'API précharge des **modèles ML**, build de 15-30 min — ADR-0011), `whatsapp-server`, `klassci-college`, `orch-keys`, et bientôt le LMS (web + worker + scheduler + MySQL).

FreeSWITCH mixe l'audio en temps réel : il ne tolère ni la contention CPU, ni le voisinage d'une charge d'inférence. **Il faut une seconde machine dédiée au nœud visio** — et compte tenu du steal time des VPS Contabo (§9.2), viser un serveur à cœurs dédiés. C'est un poste de coût à part, à chiffrer avant de s'engager sur le lot 5.

### Ce que la queue devient

Avec `lms-worker` en `queue:work` permanent (`--max-time=3600`) :
- **B0 (§10.2) devient bloquant avant le premier déploiement** — sans `--queue=high,default,low`, cinq familles de jobs ne tournent jamais.
- `queue:drain` et le trait `InteractsWithDrainBudget` (utilisé par `ArchiveOldSeances`, `CleanObsoleteSeances`, `FinalizeSeanceAttendances`) perdent leur raison d'être. **Ne pas les supprimer dans la foulée** : ils sont inoffensifs, et leur retrait est un lot de nettoyage distinct, après observation en production.

### Le volet ARTCI était déjà tracé — mais la nuance a changé

L'ADR-0024 de Wourri note déjà : *« le VPS Contabo est en UE… Les données résident donc hors Côte d'Ivoire. À garder en tête pour la conformité ARTCI — **non bloquant pour un staging**, mais à tracer. »*

Deux apports de la recherche qui dépassent ce cadrage :
1. **« Non bloquant pour un staging » ne couvre pas le LMS**, qui traitera des données d'apprenants réelles, possiblement mineurs, avec des enregistrements vidéo.
2. Le critère n'est pas « hors Côte d'Ivoire » mais **« hors espace CEDEAO »** — et **le Burkina Faso en est sorti le 29 janvier 2025**. La cible bi-pays déclenche donc la formalité **indépendamment** de l'hébergement allemand.

### Un patron déjà validé à réutiliser pour le lot 9

L'**ADR-0025 de Wourri** (rétention et purge des logs PII, conformité ARTCI, accepté le 2026-08-14) a résolu exactement le problème du lot 9, sur la même cible Dokploy :

- fichiers datés append-only + **purge in-app quotidienne** (pas de cron hôte — Dokploy ne le garantit pas) + script CLI ops ;
- **durées configurables par `.env`**, appliquées par la purge, avec **justification de finalité par catégorie** (PII en clair : 30 j ; pseudonymisé : 365 j) ;
- documentation dédiée `docs/compliance/artci-logs.md`.

**C'est le patron à reprendre tel quel pour la rétention des sessions de formation et des enregistrements** — durées en configuration, purge applicative, justification par finalité, doc de conformité versionnée. Rien à réinventer.
