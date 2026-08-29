# Requirements — #583 · Clés étrangères manquantes sur `institution_id`

> Sous-issue de #563 · Sévérité **P1**. `institution_id` est présent sur 30 tables
> (colonne + index) mais **sans aucune contrainte référentielle** : rien en base
> ne garantit l'intégrité du tenant le plus structurant du modèle multi-tenant.

## Contexte vérifié (Phase 1 — audit)

- `database/migrations/2026_02_11_000002_add_institution_id_to_all_tables.php:12-43`
  liste **30 tables** (l'issue estimait « ~28 ») recevant `institution_id`
  `nullable()` + `index()`, **sans FK**.
- Vérité terrain (introspection `Schema` sur la base migrée) : les **30 tables
  existent et portent toutes `institution_id`** (0 table manquante, 0 colonne
  manquante).
- `config/database.php:39` : `foreign_key_constraints => env('DB_FOREIGN_KEYS', true)`
  → SQLite applique les FK par défaut dans la suite de tests.
- Preuve empirique (script standalone Capsule, SQLite en mémoire) : Laravel 12
  sait **ajouter une FK à une table existante avec données** (reconstruction
  interne), rejette un INSERT orphelin, accepte `NULL`, applique
  `ON DELETE RESTRICT`, et `Schema::getForeignKeys()` liste la FK avec
  `on_delete=restrict`.
- `app/Console/Commands/PurgeSoftDeletedInstitutions.php:14-17` documente que son
  garde-fou anti-orphelins manuel existe **« en attendant les FK »** de cette
  issue même.

## Glossaire

- **Orpheline** : ligne dont `institution_id` est **non nul** et ne correspond à
  **aucune** ligne de `institutions` (existence physique, `deleted_at` ignoré :
  une institution soft-deletée conserve sa ligne, donc n'orpheline personne).
- **Table tenant-scopée** : table listée comme portant `institution_id`.

## Exigences (EARS)

### REQ-1 — Source unique de vérité des tables tenant-scopées
- WHERE le périmètre des tables portant `institution_id` est requis (commande,
  migration), le système SHALL le lire depuis **une seule** définition partagée
  (`config/tenancy.php`), jamais dupliquée dans la logique applicative.
- IF une table est déclarée mais absente du schéma, ou présente sans colonne
  `institution_id`, THEN le système SHALL l'ignorer sans erreur (robustesse
  cross-environnement).

### REQ-2 — Mesure préalable en lecture seule
- WHEN un opérateur exécute la commande d'audit, the système SHALL produire, pour
  chaque table tenant-scopée, **deux compteurs** : lignes à `institution_id NULL`
  et lignes **orphelines**.
- WHILE la commande s'exécute, le système SHALL n'émettre **aucune** écriture SQL
  (INSERT/UPDATE/DELETE/DDL) — audit strictement non destructif.
- The système SHALL exposer une sortie lisible (tableau) **et** une sortie
  machine (`--json`) pour consignation en commentaire d'issue.

### REQ-3 — Refus de migrer « à l'aveugle » (garde pré-vol)
- IF au moins une ligne orpheline existe sur une table tenant-scopée, WHEN la
  migration FK s'exécute, THEN le système SHALL **interrompre avant toute
  modification de schéma** avec une erreur explicite listant les tables fautives
  et renvoyant vers la commande d'audit.
- Rationale : sous MySQL, `ALTER TABLE ADD FOREIGN KEY` provoque un commit
  implicite (DDL non transactionnel) — un échec en cours de boucle laisserait un
  schéma partiellement contraint. La garde évite tout état partiel.

### REQ-4 — Contrainte référentielle `ON DELETE RESTRICT`
- WHEN la migration FK s'exécute sur des données saines, THEN le système SHALL
  ajouter, pour chaque table tenant-scopée existante, une clé étrangère
  `institution_id → institutions(id)` en `ON DELETE RESTRICT`.
- The système SHALL **conserver la colonne `nullable`** : une FK sur colonne
  nullable accepte `NULL` (comptes plateforme) et ne contraint que les valeurs
  non nulles.
- Q12 — alternative écartée : `ON DELETE SET NULL`, rejetée car elle
  transformerait les données d'un tenant supprimé en lignes orphelines
  silencieusement lisibles cross-tenant (fail-open).
- Q12 — alternative écartée : `ON DELETE CASCADE`, rejetée car une suppression
  d'institution ne doit **jamais** pouvoir vider 30 tables en une requête.

### REQ-5 — Idempotence et réversibilité
- IF une FK `institution_id` existe déjà sur une table, WHEN la migration
  s'exécute, THEN le système SHALL **ne pas** tenter de la recréer (relance
  sûre après un échec partiel éventuel).
- WHEN `down()` s'exécute, THEN le système SHALL supprimer chaque FK ajoutée,
  laissant colonne et index intacts.

### REQ-6 — Enforcement prouvé par les tests
- The système SHALL prouver, par test automatisé, qu'un INSERT avec un
  `institution_id` inexistant est **rejeté** par la base.
- The système SHALL prouver qu'une suppression d'une institution possédant des
  lignes filles est **bloquée** (`RESTRICT`), sans perte de données.
- The système SHALL prouver que `NULL` reste accepté.
- The système SHALL prouver que la garde pré-vol (REQ-3) interrompt la migration
  en présence d'orphelins.
- The système SHALL prouver que **les 30 tables** portent la FK après migration.

## Hors périmètre (dette tracée, à surfacer dans la PR)

- **Exécution de la mesure sur la base de production** et publication du rapport
  en commentaire d'issue : nécessite un accès aux données réelles ; gelé par la
  règle « local d'abord, pas de prod tant que le refacto n'est pas fini ».
- **Nettoyage des orphelins réels** (rattachement déductible / archivage) :
  décision par table dépendante des données mesurées ; sera une migration ciblée
  informée par le rapport, écrite au moment du déploiement. La garde REQ-3 rend
  ce nettoyage **obligatoire avant** que la FK puisse s'appliquer.
- **Validation sur la jambe MySQL de la CI** : dépend de #574 (non mergé). Les
  tests sont écrits pour passer sur SQLite (FK activées) **et** MySQL ; la
  validation MySQL réelle est bloquée par #574 (coordination orchestrateur).
- **Passage de `institution_id` en `NOT NULL`** : changement cassant nécessitant
  un backfill complet (cf. #579 notifications) — hors sujet ici.
