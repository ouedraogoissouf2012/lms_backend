# Requirements — #582 · Reprise par curseur de la sync des séances

> Sous-issue de #563 · P1 — ÉLEVÉ
> Fichier racine : `app/Services/Seances/Sync/KlassciSeancesSyncService.php:52-95`

## 1. Contexte constaté (Phase 1 — audit critique, code relu)

### 1.1 Défaut principal — famine (issue #582)

`KlassciSeancesSyncService::sync()` (`:65-79`) charge **tous** les enseignants de
**tous** les tenants (`->get()`), puis les parcourt **toujours dans le même ordre
depuis le début**, en s'arrêtant au budget de drain de 45 s (#539). Passé le
volume qui tient dans 45 s, les enseignants situés au-delà ne sont **jamais**
atteints — pas « plus tard », **jamais**.

Conséquence en cascade (`:86-92`) : l'archivage exige une passe **globale**
complète. Aucune passe ne se terminant plus, les séances supprimées côté KLASSCI
ne sont **jamais** archivées.

### 1.2 Défaut bloquant découvert pendant l'audit — colonne inexistante

`:66` filtre `->whereNotNull('klassci_token')`. Cette colonne a été **supprimée**
par la migration `2026_04_27_000001_encrypt_klassci_tokens.php:38` (remplacée par
`klassci_token_encrypted`). Vérification exécutée :

```
Schema::hasColumn('users', 'klassci_token') === false
SQL généré : select * from "users" where "role" = ? and "klassci_token" is not null
```

- **SQLite** (tests) : un identifiant entre guillemets doubles qui ne correspond à
  aucune colonne est réinterprété en **littéral chaîne** — `'klassci_token' IS NOT
  NULL` est donc toujours vrai. Le défaut est **invisible** en test.
- **MySQL** (production, `docs/DEPLOIEMENT_CPANEL.md:61` → `DB_CONNECTION=mysql`) :
  `ERROR 1054 Unknown column 'klassci_token' in 'where clause'` → le job lève à
  **chaque** exécution, épuise ses 3 `tries` et finit en `failed_jobs`.

La sync des séances est donc **totalement morte en production**, pas seulement
affamée. C'est la cause première, et suffisante, de « archivage jamais exécuté ».

### 1.3 Défaut de mémoire

`->get()` hydrate tous les enseignants d'un coup (pas de `cursor()`/`chunk()`).

## 2. Exigences (EARS)

### R1 — Reprise par curseur

- **WHEN** une passe de sync se termine (budget atteint ou liste épuisée),
  **THE SYSTEM SHALL** persister la position atteinte (dernier couple
  `(institution_id, user_id)` traité) dans un stockage durable.
- **WHEN** une passe de sync démarre et qu'une position est persistée,
  **THE SYSTEM SHALL** reprendre strictement **après** cette position.
- **WHEN** deux passes successives sont exécutées avec un budget insuffisant pour
  couvrir toute la population, **THE SYSTEM SHALL** traiter des ensembles
  d'enseignants **disjoints**.
- **WHEN** la liste des enseignants est épuisée, **THE SYSTEM SHALL** réinitialiser
  la position au début et ouvrir un nouveau cycle.

### R2 — Parcours borné en mémoire

- **THE SYSTEM SHALL** parcourir les enseignants en flux (`cursor()`), sans
  matérialiser l'ensemble de la population en mémoire.

### R3 — Requête de sélection valide sur MySQL

- **THE SYSTEM SHALL** ne référencer que des colonnes existantes de `users` ; le
  filtre « enseignant lié à KLASSCI » **SHALL** porter sur
  `klassci_token_encrypted`.
- **THE SYSTEM SHALL** exclure les enseignants sans `institution_id` (non
  synchronisables : `institution_id` est la clé de tenant — cf. `:118-121`).

### R4 — Archivage par tenant, dès complétude

- **WHEN** tous les enseignants d'une institution ont été parcourus **dans le
  cycle courant** (éventuellement à cheval sur plusieurs passes),
  **THE SYSTEM SHALL** archiver immédiatement les séances actives de cette
  institution absentes de KLASSCI, sans attendre les autres tenants.
- **THE SYSTEM SHALL** déterminer « absente de KLASSCI » par marquage
  (`seances.synced_at`) et non par accumulation en mémoire des identifiants
  actifs — l'accumulation ne survit pas à une passe tronquée.

### R5 — Sûreté de l'archivage (fail-safe)

- **IF** une erreur est survenue pendant le cycle courant pour au moins un
  enseignant ou une matière d'une institution, **THEN THE SYSTEM SHALL** renoncer
  à archiver cette institution pour ce cycle, et journaliser ce renoncement.
- **Justification** : l'archivage étant aujourd'hui inerte, le corriger l'active.
  Sans ce garde-fou, un échec HTTP KLASSCI sur un enseignant provoquerait
  l'archivage en masse de ses séances (elles n'auraient pas été marquées).

### R6 — Observabilité de la passe

- **WHEN** une passe se termine, **THE SYSTEM SHALL** journaliser : nombre
  d'enseignants traités, position du curseur, nombre de tenants complétés, nombre
  de tenants dont l'archivage a été renoncé, et si le cycle s'est achevé.
- **Justification** : sans cette métrique la famine est indétectable — c'est
  précisément pourquoi elle n'a pas été vue.

### R7 — Non-régression

- **THE SYSTEM SHALL** préserver les invariants déjà acquis : isolation tenant
  (#473), mapping unique (#474), batch matières sans N+1 HTTP (#515), restauration
  d'une séance soft-deletée (#542), budget de drain souple (#539).

## 3. Critères de fermeture (repris de l'issue)

- [ ] Test : deux passes successives à budget bas traitent des ensembles disjoints.
- [ ] Test : le curseur revient au début une fois la liste épuisée.
- [ ] Test : l'archivage d'un tenant s'exécute dès que ce tenant est complet.
- [ ] Métriques de passe présentes dans les logs et vérifiées par test.
- [ ] `php artisan test` 100 %, PHPStan level 9 vert.

## 4. Hors périmètre (déclaré)

- Le durcissement des autres appels KLASSCI (`getEvaluations`/`getEmploiTemps`) —
  issue #591.
- La parallélisation des appels HTTP entre enseignants.
- La reprise par curseur des autres jobs de maintenance.
