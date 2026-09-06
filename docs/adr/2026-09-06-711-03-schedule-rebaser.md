# ADR-711-03 — ScheduleRebaser, une primitive, trois appelants

**Date :** 2026-09-06  
**Statut :** accepté  
**Issue :** #711

## Décision

`ScheduleRebaser::rebase(TrainingSession, CarbonImmutable $newOrigin)` écrit **une seule fois**. Branché sur **trois** appelants : duplication, report, génération de créneaux récurrents.

Les **recordings** se rattachent à la **Période**, jamais au Programme. L'écran de confirmation liste ce qui n'est pas copié (dont les enregistrements).

**Non-choix :** ne pas stocker les échéances en offsets relatifs (`seances.date_seance`, `evaluations.deadline`, fenêtres de quiz, dates de chapitre). Le rebaser apporte le gain sans changer la sémantique KLASSCI.

## Pourquoi

Maven rebase à la duplication mais impose une ressaisie manuelle au report. Moodle applique un delta. Open edX force une sentinelle 2030. Une primitive évite deux arithmétiques.

## Conséquences

Service nouveau à l'étape autonomie, pas dans #711. Schéma : FK recording → Période dans #710.
