# Requirements — #598 Originaux et HTML de cours servis sans authentification

**Type** : hotfix sécurité (P1 — broken access control, pré-existant, découvert
par l'audit `spec-security` de #577). Bug isolé → lane production-standards.

---

## 1. Contexte / racine

Le pipeline de conversion des chapitres écrit sur le disque **`public`**
(`storage/app/public/`, exposé en HTTP sans authentification via le symlink
`public/storage` → URLs `/storage/...`) des artefacts qui ne sont pas des assets
publics :

| Artefact | Écrit par | Chemin |
|---|---|---|
| Document source téléversé (`.pdf`) | `PdfConverter.php:58` | `chapters/{id}/original/` |
| Document source téléversé (`.docx`) | `WordConverter.php:67` | `chapters/{id}/original/` |
| Document source téléversé (`.pptx`) | `PowerPointConverter.php:75` | `chapters/{id}/original/` |
| HTML plein-texte LibreOffice | `WordConverter.php:102` | `chapters/{id}/html/` |
| **PDF intermédiaire LibreOffice** | `PowerPointConverter.php:147` | `chapters/{id}/pdf/` |

Le dernier n'était pas listé par l'issue mais relève **exactement du même
défaut** : c'est une conversion fidèle du document source, déposée en clair sur
le disque public. Le corriger est nécessaire pour que le correctif tienne — sinon
le même contenu reste téléchargeable après avoir déplacé `original/`.

**Impact** : `GET /storage/chapters/{id}/original/<hash>.docx` renvoie le
document **sans aucun contrôle Laravel** — pas d'authentification, pas de
cloisonnement d'institution ni de classe. Servi par Apache, hors
`FileController::download()` / `ChecksFileAuthorization`.

### 1.1 Ce qui doit rester public

Les **diapositives PNG** (`chapters/{id}/slides/`, écrites par
`PdfToPngRenderer::render()` et `ConvertApiService`) et les **vidéos**
(`chapters/{id}/video/`) sont consommées par des balises `<img>` / `<video>` du
front (`lms-frontend/src/utils/lessonContent.js:63-66` construit
`{apiOrigin}/storage/{value}`). Les partitionner casserait l'affichage des cours
sans gain : ils restent sur le disque public.

### 1.2 Coordination avec #577

`storage/.htaccess` (#577) pose `Require all denied` sur tout `storage/`, et
`storage/app/public/.htaccess` ré-autorise le sous-arbre du disque public — sans
quoi les diapositives et vidéos cesseraient d'être servies. Ce second fichier
**documente déjà cette dette** et désigne #598 comme sa remédiation :

> « ⚠️ DETTE DE SÉCURITÉ TRACÉE […] Remédiation (issue de suivi #598) = stocker
> originaux + HTML sur le disque « local » (privé) et les servir via
> `FileController::download()` + `ChecksFileAuthorization`. »

Ce commentaire devient faux une fois la dette payée : le mettre à jour fait
partie du correctif.

### 1.3 Vérification d'impact front (mesure, pas intuition)

`grep -rn "file_original_path" lms-frontend/src/` → **aucune occurrence**. Le
front ne construit donc aucune URL vers `original/` : déplacer ces fichiers en
privé **ne casse aucun écran existant**. La route authentifiée (R4) est une
capacité nouvelle, pas le remplacement d'un usage en production.

---

## 2. Exigences (EARS)

- **R1** — WHEN un document source est téléversé pour un chapitre, le système
  SHALL le stocker sur le disque **privé** (`local` → `storage/app/private/`),
  jamais sur le disque `public`.
- **R2** — WHEN une conversion produit un artefact dérivé **plein-texte ou de
  fidélité équivalente au source** (HTML LibreOffice, PDF intermédiaire), le
  système SHALL l'écrire sur le disque privé.
- **R3** — Les diapositives PNG et les vidéos SHALL rester sur le disque public
  (affichage `<img>` / `<video>`).
- **R4** — WHERE le téléchargement du document source est demandé, le système
  SHALL l'exposer via une route **authentifiée** appliquant : 401 si non
  authentifié ; isolation d'institution ; refus explicite si le chapitre
  n'autorise pas le téléchargement pour ce rôle.
- **R5** — WHEN un chapitre est supprimé, le système SHALL purger ses artefacts
  sur **les deux** disques (public ET privé), sans laisser d'orphelin privé.
- **R6** — Le système SHALL fournir une commande **idempotente** de nettoyage des
  artefacts déjà déposés en public par l'ancien pipeline
  (`chapters/*/original`, `chapters/*/html`, `chapters/*/pdf`), avec mode
  simulation.
- **R7** — Le choix du disque SHALL être centralisé en **un seul endroit** : un
  futur convertisseur ne doit pas pouvoir rouvrir la faille en recopiant
  `store(..., 'public')`.
- **R8** — Le correctif SHALL être prouvé par TDD : un original/HTML/PDF
  intermédiaire n'est PAS présent sur le disque public après conversion ; un
  appelant non authentifié reçoit 401 ; un appelant d'une autre institution
  n'obtient pas le fichier.
- **R9** — Le commentaire de dette de `storage/app/public/.htaccess` SHALL être
  mis à jour (la dette est payée).

## 3. Hors périmètre

- La couche `.htaccess` elle-même (#577) — conservée telle quelle, complémentaire.
- `FileController` / `App\Models\File` — modèle distinct des chapitres
  (`ChecksFileAuthorization` est typé sur `App\Models\File`, non réutilisable tel
  quel ; cf. design).
- Migration du front vers la nouvelle route — à coordonner côté `lms-frontend`.
