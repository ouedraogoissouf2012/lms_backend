# Requirements — #537 [P0][SECURITY] Exposition potentielle du webroot cPanel (.env/.git)

## Contexte vérifié (code réel, pas supposé)

- `.cpanel.yml:3-5` : `export DEPLOYPATH=/home/c2569688c/public_html/lms-backend` puis
  `/bin/cp -R . $DEPLOYPATH` copie **tout** le contenu du répertoire de déploiement
  (y compris `.git/` s'il est présent à la source) vers `$DEPLOYPATH`, sans exclusion.
- Aucun `.htaccess` n'existe à la racine du dépôt (`ls .htaccess` → absent). Seul
  `public/.htaccess` existe et ne protège que ce qui est déjà sous `public/`.
- `docs/DEPLOIEMENT_CPANEL.md` (le runbook qui **"fait foi pour la prod actuelle"**
  selon `GUIDE_DEPLOIEMENT_PRODUCTION.md:6`) documente un **second mécanisme de
  déploiement**, différent de `.cpanel.yml` : `git pull origin lms` exécuté
  **directement dans** `/home/c2569688c/public_html/lms-backend/`. Ce répertoire est
  donc, dans le mécanisme réellement utilisé, une copie de travail Git complète
  (`.git/` inclus) doublée du `.env` de production — au même chemin que celui ciblé
  par `.cpanel.yml`.
- `GUIDE_DEPLOIEMENT_PRODUCTION.md:170-172` documente déjà que le DocumentRoot Apache
  **doit** pointer sur `public/`, mais rien ne le garantit côté dépôt, et
  `docs/DEPLOIEMENT_CPANEL.md` (le runbook réellement suivi) ne mentionne **aucune**
  vérification de ce point ni aucune protection si le DocumentRoot est mal configuré
  (cas des domaines additionnels cPanel, qui pointent par défaut sur la racine du
  répertoire et non sur un sous-dossier).
- Conséquence si le DocumentRoot est un jour mal configuré (ou l'est déjà — non
  vérifiable sans accès serveur, cf. issue) : `.env` (APP_KEY, credentials DB, token
  KLASSCI, `SUPRADMIN_PASSWORD`) et `.git/` (historique complet, y compris tout secret
  qui y aurait un jour été commité) deviennent téléchargeables publiquement.

## Contrainte de mission

Aucune action serveur autorisée (pas de SSH, pas de modification directe cPanel, pas
de reconfiguration de DocumentRoot). Toute correction doit être 100% versionnée dans
le dépôt Git et sans danger si déployée par **l'un ou l'autre** des deux mécanismes
de déploiement décrits ci-dessus — y compris le cas où `.cpanel.yml` copierait vers
un répertoire qui est **déjà** une copie de travail Git active gérée par l'autre
mécanisme (risque de corruption du `.git` de destination si la tâche est destructive).

## Exigences (format EARS)

**R1 — Défense en profondeur indépendante du DocumentRoot**
QUAND une requête HTTP cible un fichier dont le nom commence par un point (`.env`,
`.env.*`, `.gitignore`, `.gitattributes`, etc.) à la racine du dépôt déployé, LE
serveur Apache DOIT refuser la requête (403), **même si** le DocumentRoot pointe par
erreur sur cette racine plutôt que sur `public/`.

**R2 — Blocage explicite du dépôt Git**
QUAND une requête HTTP cible un chemin sous `/.git/` (ou `/.git` lui-même) à la
racine du dépôt déployé, LE serveur Apache DOIT refuser la requête (403), y compris
pour les fichiers internes de `.git/` dont le nom ne commence pas par un point
(`HEAD`, `config`, `objects/...`).

**R3 — Le correctif ne dépend d'aucune configuration serveur préalable**
LA protection définie par R1 et R2 DOIT être effective par le simple fait de
déployer le fichier versionné (comportement par défaut de `mod_authz_core`/
`mod_rewrite` sur Apache 2.4, standard EasyApache 4 de cPanel), SANS étape manuelle
côté serveur.

**R4 — Non-régression sur les besoins déjà servis**
LA protection définie par R1 et R2 NE DOIT PAS bloquer les requêtes légitimes déjà
nécessaires en production, notamment `/.well-known/...` (validation ACME/TLS) et
tout ce qui est déjà servi par `public/.htaccess`.

**R5 — Le pipeline `.cpanel.yml` ne doit plus copier `.git`, `.env*` ni `tests/`**
QUAND la tâche de déploiement automatisée `.cpanel.yml` s'exécute, ALORS elle NE DOIT
PAS copier le répertoire `.git/`, aucun fichier `.env*`, ni le répertoire `tests/`
vers `$DEPLOYPATH` — conformément au correctif explicitement demandé par l'issue
("ne pas copier .git/.env/tests sous le webroot").

**R6 — Le correctif de `.cpanel.yml` doit être non-destructif**
SI `$DEPLOYPATH` est déjà un répertoire de travail Git actif (cas documenté du
mécanisme manuel `git pull` de `docs/DEPLOIEMENT_CPANEL.md`), ALORS la tâche de
déploiement automatisée NE DOIT PAS supprimer, écraser via `--delete`, ni autrement
corrompre un `.git/` ou `.env` déjà présent à destination. (Copie additive
uniquement — jamais de suppression côté destination.)

**R7 — Fail-closed si l'outil de copie est absent**
SI l'outil utilisé pour la copie sélective (ex. `rsync`) n'est pas disponible sur le
serveur cible, ALORS la tâche de déploiement DOIT échouer de façon visible dans les
logs cPanel plutôt que de retomber silencieusement sur un comportement qui copierait
`.git`/`.env`/`tests`.

**R8 — Combler le gap de documentation du runbook réellement suivi**
LE runbook `docs/DEPLOIEMENT_CPANEL.md` (qui fait foi pour la prod actuelle) DOIT
inclure une étape de vérification post-déploiement reprenant la commande de
vérification immédiate de l'issue (`curl .../lms-backend/.env`) et un rappel exprès
que le DocumentRoot doit pointer sur `public/`, afin que l'opérateur humain qui
exécute ce runbook — pas seulement `GUIDE_DEPLOIEMENT_PRODUCTION.md`, qui ne
s'applique qu'à une installation vierge — puisse détecter une mauvaise configuration
au moment du déploiement plutôt que jamais.

## Hors périmètre (explicitement écarté, avec raison)

- **Reconfigurer le DocumentRoot Apache/cPanel lui-même** : nécessiterait un accès
  SSH/WHM au serveur de production, explicitement interdit par la mission. R1/R2
  fournissent une protection équivalente au niveau applicatif (`.htaccess`), qui est
  la seule action disponible depuis le dépôt Git.
- **Supprimer `.git` du répertoire de production existant** : action serveur
  destructive, interdite par la mission et par les règles générales anti-actions
  destructives.
- **Unifier les deux mécanismes de déploiement documentés en un seul** : décision
  architecturale plus large que ce correctif de sécurité ponctuel, à traiter dans une
  issue dédiée si le user le souhaite après lecture de ce spec.

## Critère de vérification (puisqu'aucun test PHPUnit ne s'applique à un fichier de
configuration Apache/YAML statique)

- Nouveau test **Feature Laravel** minimal qui boot l'application via le kernel HTTP
  réel et vérifie que les règles du nouveau `.htaccess` sont syntaxiquement valides
  (parsées sans erreur par une regex Apache basique) — insuffisant à lui seul pour
  prouver le comportement Apache runtime, donc complété par :
- Commande de vérification manuelle documentée (issue) que l'utilisateur (ou un futur
  opérateur) exécute après déploiement réel : `curl -s https://<domaine>/.env` et
  `curl -s https://<domaine>/.git/HEAD` → attendu 403/404 dans les deux cas.
- Ce point est signalé honnêtement comme limite : je n'ai pas d'accès au serveur de
  production pour exécuter cette vérification moi-même.
