# Schéma cible V2 (design — avant toute migration)

**Date :** 2026-09-06  
**Issue :** #711  
**Implémentation :** #710 uniquement, après merge de cette issue.

Aucune migration dans ce document. C'est le contrat pour #710.

## Entités

```
Programme (contenu versionnable)
  └── Période / training_session (dates, tarif, min_enrollments, status)
        ├── Créneau (starts_at, ends_at, lieu|visio, formateur_id)
        ├── Classe (regroupement d'apprenants, FK Programme par référence)
        │     └── Adhésion / classe_etudiant (starts_on, ends_on, date_inscription,
        │           statut varchar, commanditaire_id?, financement_*)
        ├── Recording (FK Période, jamais Programme)
        └── seance (live, déjà en base)
```

## Contraintes d'ordre (Période)

`enrollment_opens_at` ≤ `enrollment_closes_at` ≤ `starts_on` ≤ `ends_on` ≤ `certificate_available_at`  
(nulls autorisés tant que les dates présentes restent ordonnées.)

## status vs phase

- Colonne `status` : décision humaine (enum PHP + varchar SQL)
- `phase` : pas de colonne — accessor depuis les cinq dates

## Non-choix figés

- Pas d'offsets relatifs sur `date_seance` / `deadline_at`
- Pas d'écrans Commanditaire / Financement en V2
- Pas de clone de chapitres par Période

## Positionnement commercial (préalable #711)

La session datée n'est **pas** un différenciateur (Kajabi, Open edX, Canvas le font). Argumenter budget opposable, assiduité opposable, contenu hors session — pas « nous avons des cohortes ».
