# Design — #605

## Décision
Migration `2026_08_23_000001_normalize_chapters_video_provider_to_string` :
- MySQL : `ALTER TABLE chapters MODIFY COLUMN video_provider VARCHAR(50) NULL`.
- SQLite : no-op (déjà VARCHAR(50) depuis 2026_01_03 ; pas d'ENUM natif).
- `down()` : restaure l'ENUM historique (réversion non sans perte, documentée).

## Alternative écartée
Compléter l'ENUM (`+'external','s3'`) : fragile — chaque nouveau provider imposerait une migration. VARCHAR(50) est permissif et aligné sur SQLite.

## Test
`tests/Feature/Chapter/ChapterVideoProviderTest` : crée un Chapter avec `video_provider='s3'` puis `'external'` → persiste. Vert sous SQLite ; validé sous MySQL via le re-run de #574.
