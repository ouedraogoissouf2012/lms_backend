# Runbook — séances locales archivées à tort (#704)

Avant ce correctif, `ArchiveOldSeances` filtrait sur `created_at` **sans**
exiger `klassci_seance_id`. Toute séance créée dans le LMS (formation
autonome, saisie locale) passait `is_active = false` / `archive_reason =
trop_ancienne` deux semaines après sa **création**, y compris si
`date_seance` était dans le futur.

## Identifier

```sql
SELECT id, institution_id, date_seance, created_at, archived_at, archive_reason
FROM seances
WHERE klassci_seance_id IS NULL
  AND archive_reason = 'trop_ancienne'
  AND is_active = 0;
```

## Restaurer (après backup)

```sql
UPDATE seances
SET is_active = 1, archived_at = NULL, archive_reason = NULL
WHERE klassci_seance_id IS NULL
  AND archive_reason = 'trop_ancienne';
```

Ne pas restaurer les séances dont `klassci_seance_id` est renseigné :
celles-là relèvent encore de l'âge KLASSCI (`date_seance` < J-14).
