# Tasks — #598 Artefacts de chapitre sur disque privé

Ordre imposé par le TDD strict : le test rouge d'abord.

- [x] **1. Preuve du défaut (RED)**
  - [x] 1.1 `tests/Feature/Chapter/ChapterArtifactPrivacyTest.php` : après
        conversion, le disque public ne doit contenir ni `original/`, ni `html/`,
        ni `pdf/`. _Requirements: R1, R2, R3, R8_
  - [x] 1.2 **Rouge constaté sur les 3 convertisseurs** — « Des fichiers
        sensibles restent servables sans authentification sous
        /storage/chapters/{id}/original ». _Requirements: R8_

- [x] **2. Autorité unique sur le choix du disque**
  - [x] 2.1 `app/Services/FileConversion/ChapterArtifactStorage.php` :
        `storeOriginal`, `absolutePath`, `workDirectory`, `relativePathOf`,
        `purgeChapter`. _Requirements: R7_
  - [x] 2.2 `PdfConverter`, `WordConverter`, `PowerPointConverter` : original,
        HTML et PDF intermédiaire passent par le disque privé.
        _Requirements: R1, R2_
  - [x] 2.3 `PdfToPngRenderer` et `ConvertApiService` **inchangés** : les
        diapositives restent publiques. _Requirements: R3_
  - [x] 2.4 3 tests de convertisseurs existants mis à jour (changement de
        constructeur) — sweep effectué, suite verte.

- [x] **3. Téléchargement authentifié**
  - [x] 3.1 Trait `ChecksChapterDownloadAuthorization` (frère de
        `ChecksFileAuthorization`, non réutilisable car typé sur `App\Models\File`).
  - [x] 3.2 `ChapterOriginalDownloadService` : flux depuis le disque privé, nom
        de fichier lisible, `null` si absent.
  - [x] 3.3 `ChapterOriginalController` + route
        `GET /api/chapters/{id}/original` (`auth:sanctum`, `throttle:30,1`).
        _Requirements: R4_
  - [x] 3.4 `tests/Feature/Chapter/ChapterOriginalDownloadTest.php` : 401 / 200
        propriétaire / 404 autre institution / 403 `allow_download=false` / 200
        étudiant autorisé / 404 sans source. Harnais à **vrai jeton porteur**
        pour que `ResolveInstitution` et le scope tenant s'exécutent réellement.
        _Requirements: R4, R8_

- [x] **4. Purge et reliquat**
  - [x] 4.1 `FileConversionService::deleteChapterFiles()` purge les **deux**
        disques. _Requirements: R5_
  - [x] 4.2 `php artisan chapters:purge-public-artifacts [--apply]`, simulation
        par défaut, idempotente, épargne `slides/` et `video/`.
        _Requirements: R6_
  - [x] 4.3 Commentaire de dette de `storage/app/public/.htaccess` mis à jour
        (dette payée, règle permanente conservée). _Requirements: R9_

- [x] **5. Validation**
  - [x] 5.1 `tests/Feature/Chapter/` : **20/20 verts** après corrections d'audit.
  - [x] 5.2 Suite impactée verte (conversion, chapitres, leçons).
  - [x] 5.3 PHPStan 0 erreur (3 imprécisions de typage corrigées à la source),
        baseline 336/443 inchangée.

- [x] **6. Audits & corrections**
  - [x] 6.1 `spec-security` (**FAIL**, 2 HIGH) et `spec-architect` (PASS,
        6 MEDIUM). Suites détaillées : design §4.
  - [x] 6.2 **HIGH corrigé** : la commande **migre** au lieu de détruire — une
        suppression aurait effacé définitivement les documents historiques, dont
        les lignes en base pointent encore vers le disque public.
  - [x] 6.3 **MEDIUM corrigé** : la vidéo passe par l'autorité unique
        (`storeVideo`) et la lecture interroge `diskHolding()` — sans quoi tout
        chapitre vidéo renvoyait 404 en silence.
  - [x] 6.4 Gardes ajoutées : chemin obligatoirement sous `chapters/{id}/`,
        et configuration `visibility` du disque privé.
  - [x] 6.5 Commentaires surpromettants corrigés (`.htaccess` « dette payée »,
        docblocks du trait et du disque privé).
  - [x] 6.6 §1.1 : `ChapterConversionDispatcher` extrait — la nouvelle dépendance
        faisait passer `ChapterFileUploadService` de 299 à 303 lignes, et le
        garde-fou impose de découper, pas de comprimer. Effet secondaire : une
        branche de code MORTE (`isset(slides_images)` sur un retour Word qui ne
        peut pas la contenir) est devenue visible et a été supprimée, avec son
        entrée de baseline (336 → 335).

- [ ] **7. Livraison**
  - [ ] 7.1 **Arbitrage user** : le visualiseur PDF du front (`ChapterMediaRenderer`)
        rend `file_converted_path` via `/storage/` — désormais privé, donc 404
        jusqu'à adaptation du front (design §4.1).
  - [ ] 7.2 Accord user, puis PR vers `lms`.
  - [ ] 7.3 Ouvrir les issues de suivi : slides énumérables, autorisation par
        inscription, garde tenant dupliquée, coordination front.
