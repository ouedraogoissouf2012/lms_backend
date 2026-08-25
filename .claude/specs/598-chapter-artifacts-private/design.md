# Design — #598 Artefacts de chapitre sur disque privé + téléchargement authentifié

## 1. Solution retenue (une seule, §6)

Centraliser le **choix du disque** dans un collaborateur unique, et faire passer
tout téléchargement de source par une route authentifiée.

```
ChapterArtifactStorage  ← seule autorité sur « quel artefact, quel disque »
   ├─ storeOriginal(UploadedFile, chapterId): string   → disque PRIVÉ
   ├─ privatePath(string $relative): string            → chemin absolu privé
   ├─ privateWorkDirectory(chapterId, 'html'|'pdf')    → dossier privé (LibreOffice)
   ├─ toRelativePrivatePath(string $absolute): string
   └─ purgeChapter(chapterId)                          → public ET privé

PdfConverter / WordConverter / PowerPointConverter → storeOriginal()
WordConverter (HTML) / PowerPointConverter (PDF)   → privateWorkDirectory()
PdfToPngRenderer / ConvertApiService (slides)      → INCHANGÉS (public, R3)
FileConversionService::deleteChapterFiles()        → purgeChapter()
```

### 1.1 Pourquoi un collaborateur et pas 5 remplacements de `'public'` → `'local'`

Le littéral `'public'` et le chemin `storage_path('app/public/...')` étaient
**dupliqués 5 fois** dans 3 convertisseurs, plus une fois dans la purge. Un
simple search-replace corrige l'instant présent et laisse le piège intact : le
prochain convertisseur (audio ? epub ?) recopiera le motif voisin. Concentrer la
décision dans une classe dont le nom dit la règle rend la faute visible en revue
— même principe que #591 (rendre l'erreur difficile à écrire, pas seulement
absente aujourd'hui). C'est aussi la seule façon de respecter R7.

### 1.2 Autorisation du téléchargement (R4)

`ChecksFileAuthorization` **n'est pas réutilisable** : ses deux méthodes sont
typées sur `App\Models\File` (`canReadFile(?File $file, ?User $user)`), un modèle
sans rapport avec `Chapter`. L'issue le suggérait, mais le code l'interdit ; on
écrit donc l'autorisation propre au domaine chapitre, dans le même esprit
(défense en profondeur, ordre de court-circuit) :

| # | Contrôle | Réponse si échec |
|---|---|---|
| 1 | `auth:sanctum` | **401** |
| 2 | `Chapter::findOrFail()` sous le scope global `BelongsToInstitution` | **404** |
| 3 | Le chapitre a bien un document source | **404** |
| 4 | Enseignant propriétaire / admin / supradmin → autorisé ; sinon `allow_download === true` requis | **403** |

**Choix assumé — 404 et non 403 pour l'inter-institution.** L'issue demandait 403.
Le scope global `BelongsToInstitution` rend le chapitre d'une autre institution
*inexistant* pour la requête : renvoyer 404 (a) ne confirme pas l'existence de la
ressource — un 403 est un oracle d'énumération —, et (b) reproduit exactement ce
que fait déjà `GET /api/chapters/{id}` (`ChapterCrudService::show()`). Introduire
un 403 exigerait de requêter **hors scope tenant**, c'est-à-dire d'affaiblir
délibérément l'isolation pour améliorer un message d'erreur. Le 403 reste présent
et testé pour le cas intra-tenant non autorisé (étape 4).

`Chapter::allow_download` est un champ **déjà modélisé** (`$fillable`, cast
`boolean`, défaut `true`) : l'autorisation s'appuie sur une règle métier
existante plutôt que d'en inventer une.

### 1.3 Nettoyage de l'existant (R6)

Commande `php artisan chapters:purge-public-artifacts [--dry-run]` :
parcourt `chapters/*/{original,html,pdf}` sur le disque public et les supprime
(le contenu utile est déjà en base — `chapter.content` pour le HTML — ou
régénérable). Idempotente, `--dry-run` par défaut sûr, journalise le décompte.

Une **migration** a été écartée : la manipulation porte sur le système de
fichiers, pas sur le schéma ; elle doit pouvoir être rejouée et simulée, ce
qu'une migration `up()` unique ne permet pas.

## 2. Alternatives écartées (Q12)

1. **Deny `.htaccess` ciblé sur `original|html|pdf`** — rejetée : la protection
   dépendrait à nouveau d'Apache (un DocumentRoot ou un serveur nginx la
   contourne), alors que #577 a précisément établi que la couche applicative doit
   porter la garantie. Et elle laisserait les fichiers sensibles à la racine d'un
   disque nommé « public », piège permanent pour la maintenance.
2. **Signed URLs temporaires (`URL::temporarySignedRoute`)** — rejetée pour ce
   correctif : déplace le contrôle d'accès vers un secret d'URL partageable
   (copier-coller, historique, referrer) alors que le besoin est un contrôle
   d'identité **et** d'appartenance ; utile plus tard pour le streaming vidéo,
   inutile ici où la requête est déjà authentifiée par Sanctum.

## 3. Stratégie de test (R8)

`tests/Feature/Chapter/ChapterOriginalPrivacyTest.php` :

| Cas | Exigence | Assertion |
|---|---|---|
| conversion Word | R1, R2 | `Storage::disk('public')` ne contient ni `original/`, ni `html/` ; `disk('local')` les contient |
| conversion PowerPoint (fallback LibreOffice) | R1, R2 | idem + `pdf/` privé ; `slides/` **public** |
| `GET /api/chapters/{id}/original` anonyme | R4 | **401** |
| enseignant propriétaire | R4 | **200** + octets identiques au fichier source |
| chapitre d'une autre institution | R4 | **404**, aucun octet |
| étudiant, `allow_download = false` | R4 | **403** |
| suppression de chapitre | R5 | plus aucun artefact sur les deux disques |
| commande de purge en `--dry-run` | R6 | ne supprime rien, annonce le décompte |

Les convertisseurs sont testés via leurs tests existants
(`tests/Feature/FileConversion/*ConverterTest.php`), qui construisent les
convertisseurs à la main (`new WordConverter(...)`) : l'ajout de la dépendance
`ChapterArtifactStorage` impose de les mettre à jour — sweep effectué, 3
fichiers.


---

## 4. Suites données aux audits `spec-security` (FAIL, 2 HIGH) et `spec-architect` (PASS, 6 MEDIUM)

| Constat | Suite donnée |
|---|---|
| **HIGH (F2)** — la commande **supprimait** les artefacts historiques. Or `file_original_path` pointe encore vers le chemin public pour toutes les lignes antérieures : supprimer détruisait définitivement le document de l'enseignant, sans que la route authentifiée puisse prendre le relais. L'opérateur n'avait aucune option sûre. | **Corrigé — défaut que j'avais introduit.** La commande **migre** désormais : copie vers le disque privé **à chemin relatif constant**, vérification, puis suppression de la source. Les lignes en base restent valides. Un échec de copie laisse le fichier public en place et fait échouer la commande. |
| **HIGH (F1)** — les diapositives restent servies anonymement à URL prédictible (`chapters/{id}/slides/slide_001.png`, `chapters.id` = entier séquentiel **global**) : le contenu page à page de tous les cours reste énumérable. Pré-existant, hors périmètre — mais le commentaire `.htaccess` disait « DETTE PAYÉE ». | **Commentaire corrigé** (« partiellement payée », avec le détail de ce qui reste ouvert) + issue de suivi. Le correctif ferme le document **source**, pas sa restitution en images : le dire est la moitié du travail. |
| **MEDIUM (C1/F5)** — `handleVideo()` écrivait encore `store(..., 'public')` et remplissait `file_original_path` avec un chemin **public**, alors que le service de lecture résolvait le disque privé en dur → **404 silencieux sur tout chapitre vidéo**. L'affirmation « seule autorité » était fausse. | **Corrigé** : `ChapterArtifactStorage::storeVideo()` (public, assumé et déclaré ici) + `diskHolding()` interrogé côté lecture. Test dédié. |
| **MEDIUM (C2/F7)** — `relativePathOf()` renvoyait silencieusement le chemin **absolu** hors racine, donc l'arborescence du serveur en base puis en réponse API — l'inverse de ce que promettait son docblock. | **Corrigé** : `RuntimeException`. Le seul appelant reçoit un chemin fabriqué par `workDirectory()` : hors racine = bug. |
| **MEDIUM (C4)** — `relativePathOf()` était la seule méthode à cas d'échec du diff, et la seule sans aucune assertion. | **Corrigé** : assertion de forme sur `file_converted_path` dans le test du repli LibreOffice. |
| **MEDIUM (C5)** — 401 réinventé à la main alors qu'`AuthenticatedController` existe et est utilisé par le contrôleur frère `FileController`. | **Corrigé** : héritage + `authenticatedUser()`. |
| **MEDIUM (C7)** — `handle()` à 42 lignes (> 40, §5). | **Corrigé** : extraction de `sensitiveFiles()` et `migrate()`. |
| **MEDIUM (F3)** — le docblock du trait affirmait « plus que pouvoir lire le chapitre », alors qu'`allow_download` vaut `true` par défaut et qu'aucun contrôle d'inscription n'existe : tout compte du tenant peut télécharger n'importe quelle source. | **Commentaire corrigé** pour décrire la règle **réelle** + dette tracée. Non-régression : `GET /api/chapters/{id}` ne vérifie rien de plus. |
| **LOW (F6)** — le service ne vérifiait pas que le chemin appartient au chapitre demandé. | **Corrigé** : préfixe `chapters/{id}/` obligatoire. Gratuit aujourd'hui, décisif si une écriture future place une autre valeur dans la colonne. |
| **LOW (F10.2)** — le disque privé déclare `'serve' => true` ; ajouter `'visibility' => 'public'` annulerait #598 en une ligne de config, sans qu'aucun test fonctionnel ne bronche. | **Corrigé** : test de garde sur la configuration. |
| **LOW (F8)** — repli ASCII vide de `Content-Disposition` → 500 possible sur un titre en script non translittérable. | **Corrigé** : assainissement restreint à l'ASCII imprimable. |
| **LOW (F9)** — branches `supradmin` / `isAdmin()` non testées. | **Corrigé** : test dédié (supradmin sans institution ⇒ 200 cross-tenant ; admin d'un autre tenant ⇒ 404). |
| **LOW (C9)** — `mkdir()` natif non vérifié. | **Corrigé** : `makeDirectory()` via l'abstraction. |
| **LOW (C11)** — docblock affirmant le disque privé « jamais exposé en HTTP », faux (`'serve' => true`). | **Corrigé** : la mécanique réelle (signature exigée) est décrite, avec sa conséquence sur `temporaryUrl()`. |
| **MEDIUM (C6)** — la garde tenant est dupliquée dans 4 traits. | **Non fait** : factoriser toucherait `ChecksFileAuthorization` et `ChecksForumAuthorization`, étrangers à cette fuite. Dette signalée. |

### 4.1 Régression front à arbitrer — signalée, non masquée

`ChapterMediaRenderer.vue:92` affiche les chapitres PDF via
`getPdfUrl(chapter)` → `chapter.pdf_url || chapter.file_converted_path`, préfixé
`/storage/` (`lessonContent.js:66,93`). Or pour un chapitre PDF,
`file_converted_path` **est** le document source, désormais privé ; pour un PPTX
en repli, c'est le PDF intermédiaire, également privé.

**Le visualiseur PDF affichera donc 404 tant que le front n'est pas adapté.**

Ce n'est pas un effet de bord évitable : ce visualiseur rendait précisément le
document source par une URL non authentifiée — c'est *la* vulnérabilité que #598
ferme. Les deux sorties possibles sont côté front : basculer sur
`slides_images` (déjà produites pour PDF **et** PPTX, et déjà rendues par
`SlidesViewer`), ou appeler `GET /api/chapters/{id}/original`. Aucune ne relève
de ce dépôt → coordination explicite, pas correction silencieuse.

### 4.2 Effet de bord traité, pas contourné — §1.1

`ChapterFileUploadService` vivait à **299 lignes sur une limite de 300**. Y ajouter
la dépendance vers l'autorité de disque l'a porté à 303 : le garde-fou de taille
bloque la CI, et sa consigne est explicite — « découpe en collaborateurs DIP
**plutôt que de grossir le fichier** ». Comprimer trois lignes de commentaire
aurait rendu la CI verte sans traiter la cause.

`ChapterConversionDispatcher` est donc extrait sur une frontière réelle :
`ChapterFileUploadService` orchestre l'upload (synchrone, asynchrone, suivi de
statut) ; le dispatcher décide **quel convertisseur** appeler et **quoi écrire**
sur le chapitre. `processUploadedFile()` reste exposé et délègue en une ligne, donc
le job `ConvertChapterFile` n'est pas touché. Résultat : 185 et ~155 lignes.

**Bonus non prévu** : l'extraction a rendu visible une entrée de
`phpstan-baseline.neon` devenue morte, qui masquait une **branche de code morte** —
`handleWord()` testait `isset($result['slides_images'])` alors que `WordConverter`
n'a aucun chemin ConvertAPI et ne peut donc jamais renvoyer cette clé. Branche
supprimée, entrée de baseline retirée (336 → **335**, aucune ajoutée).
