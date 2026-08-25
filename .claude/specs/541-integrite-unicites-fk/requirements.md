# Requirements — #541 Unicités & FK manquantes/inopérantes (intégrité données)

> Sous-issue de #535 · P2 · Branche `fix/541-unicites-fk`

## Contexte mesuré (Phase 1 — audit, pas supposition)

| # | Défaut | Preuve `fichier:ligne` |
|---|---|---|
| A | `evaluations.klassci_evaluation_id` porte un **index simple**, pas d'unique → le 409 « éval déjà en ligne » repose sur un `SELECT` hors transaction (TOCTOU) | `database/migrations/2025_10_19_180924_create_evaluations_table.php:18,51` · `app/Services/Evaluation/EvaluationCreationService.php:82-95` |
| B | `classe_etudiant` porte `unique(classe_id,user_id,annee_universitaire_id)` mais le synchroniseur **n'écrit jamais** `annee_universitaire_id` → colonne toujours `NULL` → unique **inopérante** (plusieurs `NULL` autorisés en SQL) | `database/migrations/2025_10_14_160400_create_klassci_sync_tables.php:105,110` · `app/Services/Sync/Classes/ClasseStudentsSynchronizer.php:170-201` |
| C | `esbtp_attendance.seance_id` / `.user_id` sont des `unsignedBigInteger` **sans clé étrangère** → lignes orphelines quand un user/séance est supprimé physiquement | `database/migrations/2025_10_25_190916_create_esbtp_attendance_table.php:18-19` |
| D | `institution_id` ajouté partout sans FK — **P3** | déjà **RÉSOLU** par `database/migrations/2026_08_15_140000_add_institution_id_foreign_keys.php` (#583) |

Le point D est donc **hors périmètre** : il est livré. Restent A, B, C.

## Exigences (EARS)

### R1 — Unicité effective du lien KLASSCI des évaluations

- **WHEN** deux créations concurrentes portent le même `klassci_evaluation_id` pour la **même institution**, le système **SHALL** n'en persister qu'une seule (rejet base, pas seulement applicatif).
- **WHERE** `klassci_evaluation_id` vaut `NULL` (évaluation créée nativement sur le LMS), le système **SHALL** autoriser un nombre illimité de lignes.
- **WHERE** deux institutions distinctes portent le même `klassci_evaluation_id`, le système **SHALL** autoriser les deux lignes (espaces d'ID KLASSCI indépendants par tenant — cf. #473/#258).
- **IF** une évaluation a été supprimée (soft delete), **THEN** le système **SHALL** autoriser la création d'une nouvelle évaluation portant le même `klassci_evaluation_id`.
- **IF** des doublons vivants préexistent en base, **THEN** la migration **SHALL** les retirer **sans perte de données** (soft delete réversible), en conservant la ligne la plus ancienne (celle que le 409 désignait).

### R2 — Unicité effective de l'inscription classe↔étudiant

- **WHEN** le synchroniseur KLASSCI traite deux fois le même étudiant pour la même classe, le système **SHALL** n'avoir qu'une seule ligne dans `classe_etudiant` (garantie base, pas seulement `SELECT`-puis-`INSERT`).
- **WHEN** le synchroniseur inscrit un étudiant, il **SHALL** utiliser une écriture idempotente (`updateOrInsert`) dont les colonnes de correspondance **SHALL** être exactement celles de l'index unique.
- **IF** des doublons préexistent, **THEN** la migration **SHALL** les archiver avant suppression (réversibilité).

### R3 — Intégrité référentielle des présences

- **WHEN** une ligne `esbtp_attendance` est insérée avec un `seance_id` ou un `user_id` inexistant, la base **SHALL** rejeter l'insertion.
- **WHEN** une séance ou un utilisateur est supprimé physiquement, la base **SHALL** supprimer en cascade ses lignes de présence (plus d'orphelins).
- **IF** des lignes orphelines préexistent, **THEN** la migration **SHALL** les archiver dans une table de quarantaine **avant** de les retirer, et **SHALL** poser les FK ensuite.

### R4 — Non-destruction

- Le système **SHALL** ne détruire aucune donnée sans copie récupérable : toute ligne retirée par une migration d'intégrité **SHALL** être soit soft-deletée, soit archivée intégralement (JSON) dans `orphan_row_archive`.

### R5 — Portabilité moteur

- Les migrations **SHALL** produire le même comportement sous SQLite (suite de tests) et MySQL 8.4 (production + jambe CI #574), sans privilège d'administration.

## Hors périmètre (déclaré)

- Tables de **tentatives** d'évaluation/quiz (`evaluation_submissions`, `quiz_attempts`, …) : périmètre de #540.
- `matiere_enseignant` : **même classe de défaut** que B (`unique(klassci_matiere_id, klassci_enseignant_id, annee_universitaire_id)` avec `annee_universitaire_id` toujours `NULL` — `app/Models/MatiereEnseignant.php:110`), et unique sans `institution_id`. **Découvert pendant cet audit, non corrigé ici** → à ouvrir en issue dédiée.
- Passage de `institution_id` en `NOT NULL` (backfill cassant, cf. #583 §« Colonne conservée nullable »).
