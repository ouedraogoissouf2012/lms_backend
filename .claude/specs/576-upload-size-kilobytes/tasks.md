# Tasks — #576 : limite d'upload en kilo-octets

Ordre TDD strict : écrire/observer le RED avant l'implémentation.

- [x] 1. **RED** — Tests de non-régression de taille
  - [x] 1.1 Ajouter à `UploadFileRequestTest` : 40 Mo → 422, 31 Mo → 422 (+ `errors.file`),
        29 Mo → ≠ 422, borne 30 Mo → ≠ 422, 30 Mo + 1 Ko → 422. _Requirements: R1.1, R1.2_
  - [x] 1.2 Ajouter à `StoreChapterRequestTest` : mêmes cas sur `fichier`. _Requirements: R2.1, R2.2_
  - [x] 1.3 Corriger les tests existants mal étiquetés (valeurs Go → valeurs Ko réelles) sans
        changer leur intention. _Requirements: R5.3_
  - [x] 1.4 Exécuter la suite ciblée → constater le RED (40/31 Mo passent aujourd'hui). _Requirements: R1.1_

- [x] 2. **Source unique** — `App\Support\Upload\UploadLimits`
  - [x] 2.1 Créer `app/Support/Upload/UploadLimits.php` : `const MAX_KILOBYTES = 30 * 1024`
        (non typée : projet `php: ^8.2`, constante typée = 8.3+), `maxRule()`,
        `humanReadable()`, docblock « kilo-octets ». _Requirements: R3.1, R3.4_
  - [x] 2.2 Test unitaire `UploadLimitsTest` : constante = 30720, `maxRule()` = `max:30720`,
        `humanReadable()` = `30 MB`. _Requirements: R3.1_

- [x] 3. **GREEN** — Brancher les deux `FormRequest` sur la source unique
  - [x] 3.1 `UploadFileRequest` : règle `UploadLimits::maxRule()`, message + `getMaxFileSize()`
        via `UploadLimits::humanReadable()`, docblock corrigée. _Requirements: R1.1, R1.3, R3.2, R3.3_
  - [x] 3.2 `StoreChapterRequest` : règle `UploadLimits::maxRule()`, message via `humanReadable()`.
        _Requirements: R2.1, R2.3, R3.2, R3.3_
  - [x] 3.3 Exécuter la suite ciblée → constater le GREEN. _Requirements: R5.1_

- [x] 4. **Validation (Phase 4)**
  - [x] 4.1 `php artisan test` (suite impactée) 100 %. _Requirements: R5.1_
  - [x] 4.2 PHPStan level 9 = 0 erreur. _Requirements: R5.2_
  - [ ] 4.3 Revue qualité (fallback production-grade + `/code-review`, `thermo-nuclear`
        indisponible ici) : aucun finding CRITICAL/HIGH. _Requirements: R5.3_
  - [x] 4.4 Vérifier tailles fichiers ≤ limites (§5) et méthodes ≤ 40 lignes.

- [ ] 5. **Documentation / suivi**
  - [ ] 5.1 Commit conventionnel `fix(uploads): …`, sujet ≤ 70, Co-Authored-By (après accord user).
  - [ ] 5.2 Noter dans la PR la coordination R4 (note guide de déploiement portée par #577).
