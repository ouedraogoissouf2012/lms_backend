# Requirements — #577 : interdire l'accès HTTP à `storage/` indépendamment du DocumentRoot

> Sous-issue de #563 · Sévérité **P1 — ÉLEVÉ** · Suite directe de **#537** (qui a traité les
> fichiers cachés `.env`/`.git`/`.claude`, mais pas les répertoires applicatifs).
> Références : `PRODUCTION_STANDARDS.md` §1.2 (sécurité absolue).

## Contexte

Le `.htaccess` racine (#537) bloque tout segment de chemin **commençant par un point**
(`(^|/)\.`), donc `.env`, `.git/`, `.claude/`. Il ne bloque **pas** les répertoires
applicatifs qui ne commencent pas par un point : `storage/`, `app/`, `bootstrap/`, `config/`,
`database/`, `resources/`, `routes/`, `vendor/`, `tests/`. Et il n'existe **aucun** `.htaccess`
dans `storage/` (vérifié).

`.cpanel.yml` déploie l'**application entière** sous `public_html/lms-backend/`. Tant que le
DocumentRoot pointe sur `public/`, tout va bien. S'il pointe sur `public_html` (défaut cPanel,
cas classique d'un domaine additionnel oublié) :

- `GET /lms-backend/storage/logs/laravel.log` → journal applicatif complet (emails, ids,
  contextes d'erreur) ;
- `GET /lms-backend/storage/app/private/uploads/courses/<uuid>.pdf` → **n'importe quel fichier
  privé déposé, sans authentification**, court-circuitant `FileController::download()` et
  `ChecksFileAuthorization`.

La sécurité d'un fichier déposé ne doit pas dépendre d'un réglage d'hébergeur non versionné.

## Contrainte de non-régression découverte (Phase 1)

L'application **sert des fichiers publics par le symlink `/storage`** (Laravel `storage:link`,
`config/filesystems.php:77` → `public_path('storage') => storage_path('app/public')`) :
diapositives PNG converties (`PdfToPngRenderer`, `storage/app/public/chapters/...`), vidéos
(`ChapterFileUploadService` renvoie `video_url = "/storage/{path}"`). Ces URLs `/storage/...`
sont chargées **directement par le navigateur**, servies par Apache, pas par Laravel.

⇒ Toute protection de `storage/` **ne doit pas** casser le service de `storage/app/public/`.

## Exigences (format EARS)

### R1 — Blocage de `storage/` indépendant du DocumentRoot

- **R1.1** WHEN une requête HTTP externe cible un fichier sous `storage/` **hors**
  `storage/app/public/` (ex. `storage/logs/…`, `storage/framework/…`,
  `storage/app/private/…`), THE serveur SHALL répondre **403**, quel que soit le DocumentRoot.
- **R1.2** THE blocage SHALL provenir d'un `.htaccess` **versionné dans `storage/`** (protection
  dans le répertoire lui-même, donc lue par Apache le long du chemin filesystem résolu, que le
  DocumentRoot soit `public/` ou mal configuré).

### R2 — Préservation des assets publics (non-régression)

- **R2.1** WHEN une requête cible un fichier sous `storage/app/public/` (racine du disque public
  Laravel), THE serveur SHALL **continuer à le servir** (ne pas 403) — via une exception
  versionnée `storage/app/public/.htaccess`. Ce sous-arbre est public **par conception**
  (symlink `/storage`) : l'exception préserve le comportement existant, elle ne le restreint pas.
  ⚠️ **Dette pré-existante tracée** (hors périmètre .htaccess de #577) : le pipeline de conversion
  y dépose aussi des originaux bruts + HTML plein-texte, lisibles sans auth → issue de suivi
  (déplacer vers le disque privé + download authentifié). Découverte par l'audit sécurité #577.
- **R2.2** THE règle de durcissement du `.htaccess` racine SHALL **exclure** `storage`, car
  `/storage/…` est une URL publique légitime (symlink) : la bloquer au niveau racine casserait
  le service des assets publics.

### R3 — Blocage de `bootstrap/cache/`

- **R3.1** WHEN une requête cible `bootstrap/cache/…` (config/routes/services compilés, pouvant
  contenir des secrets issus de `config:cache`), THE serveur SHALL répondre **403**, via un
  `.htaccess` versionné dans `bootstrap/cache/`.

### R4 — Durcissement du `.htaccess` racine (répertoires applicatifs)

- **R4.1** THE `.htaccess` racine SHALL refuser (403) l'accès aux répertoires applicatifs
  `app`, `bootstrap`, `config`, `database`, `resources`, `routes`, `vendor`, `tests`.
- **R4.2** THE règle SHALL fonctionner **indépendamment du DocumentRoot** : couvrir aussi bien
  `/app/…` (DocumentRoot = racine appli) que `/lms-backend/app/…` (DocumentRoot = `public_html`).
- **R4.3** THE règle SHALL **ne casser aucune route applicative** existante (aucune route API
  n'a de segment de 1er ou 2e niveau nommé comme ces répertoires — vérifié).
- **R4.4** THE durcissement SHALL **étendre** le `.htaccess` racine de #537 sans retirer ni
  affaiblir la protection des fichiers-point existante (`.env`, `.git`, `.claude`, `.well-known`).

### R5 — Déploiement des fichiers

- **R5.1** THE nouveaux `.htaccess` SHALL être versionnés et transportés par le `rsync` de
  `.cpanel.yml` (qui exclut `.git`, `.env*`, `tests`, `.claude` — aucun de ces motifs ne
  matche les `.htaccess` ni `storage/`/`bootstrap/`). Vérifié : aucune modification de
  `.cpanel.yml` nécessaire.

### R6 — Documentation

- **R6.1** THE guide de déploiement (`GUIDE_DEPLOIEMENT_PRODUCTION.md`) SHALL documenter une
  vérification post-déploiement : `curl -I …/lms-backend/storage/logs/laravel.log` → **403**.
- **R6.2** (Coordination #576) THE guide SHALL documenter la relation entre la limite d'upload
  applicative (30 Mo) et `upload_max_filesize` / `post_max_size` PHP.

### R7 — Testabilité honnête

- **R7.1** THE contenu protecteur des `.htaccess` versionnés (directives `Require all denied` /
  `grant`, règle racine des répertoires applicatifs) SHALL être couvert par un test de
  **présence/contenu** (garde-fou anti-régression contre une suppression accidentelle). Ce test
  **ne prouve pas** le comportement d'Apache (non testable en CI) — limite déclarée
  explicitement (cf. #537 §5).

## Critères de fermeture (issue #577)

- [ ] Les deux `.htaccess` (`storage/`, `bootstrap/cache/`) sont versionnés et déployés par rsync.
- [ ] Règle racine ajoutée et commentée (pourquoi, pas quoi).
- [ ] Procédure de vérification documentée dans le guide de déploiement.
- [ ] Vérification manuelle post-déploiement (à faire par le user après le prochain déploiement,
      résultat consigné en commentaire de l'issue) — action serveur, hors code.

## Hors périmètre (dette tracée / actions serveur)

- La **vérification empirique** (curl réel) nécessite un déploiement + accès serveur : à la
  charge du user (comme #537). Aucun accès SSH ici.
- Corriger le DocumentRoot du vhost (si `curl storage/logs` renvoie 200 après déploiement) est
  une **action WHM/serveur**, hors correctif Git.
