# Tasks — #577 : blocage HTTP de `storage/` indépendant du DocumentRoot

- [x] 1. **Couche 1** — `storage/.htaccess` : `Require all denied` (+ repli Apache 2.2).
      _Requirements: R1.1, R1.2_
- [x] 2. **Couche 2** — `storage/app/public/.htaccess` : `Require all granted` (+ repli 2.2),
      exception assets publics (non-régression). _Requirements: R2.1_
- [x] 3. **Couche 3** — `bootstrap/cache/.htaccess` : `Require all denied` (+ repli 2.2).
      _Requirements: R3.1_
- [x] 4. **Couche 4** — Étendre le `.htaccess` racine : règle répertoires applicatifs
      (SAUF `storage`), ancrage 1er/2e segment, + repli `RedirectMatch`. Ne pas casser la
      règle fichiers-point #537. _Requirements: R4.1, R4.2, R4.3, R4.4, R2.2_
- [x] 5. **Déploiement** — Vérifier que `.cpanel.yml` n'exclut pas les nouveaux fichiers
      (aucune modification attendue). _Requirements: R5.1_
- [x] 6. **Documentation** — `GUIDE_DEPLOIEMENT_PRODUCTION.md` : §5 vérif 403 storage + 200
      asset public ; §4 défense en profondeur + note upload/PHP (coordination #576).
      _Requirements: R6.1, R6.2_
- [x] 7. **Test** — `tests/Feature/Security/StorageHttpGuardTest` : présence des directives
      protectrices dans les 4 fichiers (garde-fou régression), limite déclarée. `git add -f`.
      _Requirements: R7.1_
- [x] 8. **Validation** — `php artisan test` (18 verts) ; PHPStan inchangé (aucun fichier `app/`) ;
      revue 3 agents : architecte PASS, reviewer MERGE-READY, sécurité BLOCKED sur un HIGH
      **pré-existant hors périmètre** (voir « Suivi sécurité ») — les propres docs de #577 ont été
      corrigées (invariant faux retiré), l'expo est tracée pour une issue de suivi (décision user).
- [x] 9. **PR** — Commit conventionnel `fix(deploy): …` (type `fix`, PAS `security`), sujet ≤ 70,
      Co-Authored-By, après accord user. `git add -f` des 4 `.htaccess` + specs + test.

## Suivi sécurité (découvert par l'audit spec-security de #577)

- **[HIGH, pré-existant, hors périmètre .htaccess]** Le pipeline de conversion écrit les
  documents **originaux bruts** et le **HTML plein-texte** sur le disque `public`
  (`PdfConverter:58`, `WordConverter:67`/`:102`, `PowerPointConverter:75` ; `ConvertChapterFile:60`
  ne purge que la copie privée) → lisibles sans authentification via `/storage/chapters/{id}/original|html/…`.
- #577 (couche `.htaccess`) **ne crée ni n'aggrave** cette exposition (le symlink `/storage` la
  servait déjà) et ne peut la corriger proprement sans casser un éventuel lien de download direct.
- **Remédiation → issue de suivi** : stocker originaux + HTML sur le disque `local` (privé) et les
  servir via `FileController::download()` + `ChecksFileAuthorization` ; garder slides/vidéos publics.
- Docs #577 corrigées en conséquence (l'invariant faux « uniquement du public » a été retiré).
