# Requirements — #605 · `chapters.video_provider` divergence SQLite/MySQL

## Contexte vérifié
- MySQL : `enum('video_provider',['youtube','vimeo','custom'])` (`2025_10_27_081047...:70`), non modifié par `2026_01_03_220000...:87` (qui ne touche que `content_type`).
- SQLite : `VARCHAR(50)` (reconstruction `2026_01_03_220000...:24-59`).
- `SeanceRecordingAttachmentResolver.php:153` écrit `'external'` (défaut :27) / `'s3'` → hors ENUM → `1265 Data truncated` sous MySQL. L'attache de replay visio échoue en prod ; SQLite le masque.
- Preuve : jambe MySQL de #574 (PR #603), 9 erreurs `Data truncated for column 'video_provider'`.

## Exigences
- R1 : `chapters.video_provider` accepte tout provider (external, s3, youtube, vimeo, custom…) sur MySQL ET SQLite.
- R2 : aucune régression sur les données existantes ; migration idempotente par moteur.
- R3 : test de garde structurel (insertion d'un provider hors ancien ENUM réussit).
