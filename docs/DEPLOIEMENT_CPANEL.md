# Runbook de déploiement cPanel — backend LMS

> **À lire EN ENTIER avant de déployer.** Chaque étape a une vérification.
> Ne pas sauter l'étape 4 (migrations) : c'est elle qui, oubliée, a provoqué
> l'incident login 500 du 2026-06-20.

- **Serveur** : `/home/c2569688c/public_html/lms-backend/`
- **Branche de prod** : `lms`
- **Base de données prod** : MySQL (PAS sqlite — sqlite est uniquement le dev local)
- **URL backend** : `https://api.klassci.com/api`

---

## 0. Pré-vol (avant de toucher au serveur)

- [ ] Confirmer que la branche `lms` est verte sur GitHub (8 jobs CI).
- [ ] Prévenir qu'une courte interruption est possible.
- [ ] **Sauvegarde DB** (filet de sécurité avant migrations) :
  ```bash
  cd /home/c2569688c/public_html/lms-backend
  php artisan db:show   # vérifie la connexion DB
  # Export de secours (adapter les identifiants depuis .env) :
  mysqldump -u <DB_USERNAME> -p <DB_DATABASE> > ~/backup-lms-$(date +%F-%H%M).sql
  ```
  ✅ Vérif : le fichier `~/backup-lms-*.sql` existe et n'est pas vide (`ls -lh ~/backup-lms-*.sql`).

---

## 1. Récupérer le code

```bash
cd /home/c2569688c/public_html/lms-backend
git status            # doit être propre ; sinon stasher/committer AVANT
git pull origin lms
```
✅ Vérif : `git log --oneline -1` affiche le dernier commit attendu (le job CI tests, etc.).

> ⚠️ Si `git status` montre des fichiers modifiés sur le serveur (éditions directes
> passées), NE PAS forcer le pull. Régler d'abord. Règle d'or : **jamais d'édition
> directe sur cPanel** — tout passe par local → push → pull.

---

## 2. Dépendances PHP (production)

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```
✅ Vérif : pas d'erreur, `vendor/` à jour. (`--no-dev` exclut phpunit/larastan, inutiles en prod.)

---

## 3. Vérifier le `.env` de PRODUCTION  *(issue #1 — CRITIQUE sécurité)*

```bash
grep -E "APP_ENV|APP_DEBUG|KLASSCI_API_URL|DB_CONNECTION" .env
```
✅ Doit afficher :
- `APP_ENV=production`
- `APP_DEBUG=false`   ← **si `true`, le corriger immédiatement** (fuite de stack traces aux clients)
- `DB_CONNECTION=mysql`
- `KLASSCI_API_URL=...` (renseigné)

Si une valeur est fausse : l'éditer dans le `.env` serveur, puis continuer.

---

## 4. Migrations  ⚠️ ÉTAPE CRITIQUE — NE JAMAIS SAUTER

```bash
php artisan migrate --status   # voir ce qui est en attente
php artisan migrate --force    # --force = obligatoire en prod (pas de prompt)
```
✅ Vérif : `php artisan migrate --status` ne montre plus de `Pending`.

Cette release crée notamment :
- `audit_logs` (journal d'audit #215) — **son absence faisait planter le login (500)**
- `content_corruption_backups` (sauvegarde pour la correction de contenu, étape 7)

> Le fix #241 empêche désormais le login de tomber même si une table d'audit
> manque, mais l'audit ne fonctionnera pas tant que la migration n'est pas passée.
> **Donc : migrer.**

---

## 5. Caches Laravel (recompiler config/routes/vues)

```bash
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
✅ Vérif : aucune erreur. (`config:cache` lit le `.env` — d'où l'importance de l'étape 3 AVANT.)

> ⚠️ Après `config:cache`, le `.env` n'est plus relu à chaud. Si tu changes le
> `.env` plus tard, refais `php artisan config:cache`.

---

## 6. Vérification de santé (avant d'annoncer « OK »)

```bash
# 6a. L'API répond
curl -s -o /dev/null -w "health: %{http_code}\n" https://api.klassci.com/up

# 6b. Login : un mauvais identifiant doit donner 401 (PAS 500 ni 503)
curl -s -X POST https://api.klassci.com/api/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"username":"zzz_inexistant_test","password":"motdepassevalide"}' \
  -w "\nlogin inconnu: %{http_code}\n"
```
✅ Attendu :
- health → `200`
- login inconnu → `401` (preuve que l'auth tourne ; si `500`, voir étape 4 / logs ; si `503`, KLASSCI injoignable)

- [ ] **Test réel** : connecte-toi avec ton **compte supradmin local** (indépendant de KLASSCI) → doit réussir.
- [ ] **Test réel** : connecte-toi avec un compte KLASSCI (étudiant/enseignant) → doit réussir.

---

## 7. Correction des données `content` corrompues  *(une seule fois, après ce déploiement)*

Le bug `$this->content` (corrigé en #230) a pu corrompre le champ `content` des
forum/lessons/chapters créés AVANT le déploiement. La commande répare, avec backup.

```bash
# 7a. SIMULATION d'abord (lecture seule, n'écrit rien) :
php artisan content:fix-corruption

# 7b. Regarder le rapport (nombre de rows corrompues par table).
#     Si les chiffres sont cohérents, appliquer réellement :
php artisan content:fix-corruption --apply
```
✅ Vérif : le rapport indique le nombre corrigé ; les originaux sont sauvegardés
dans la table `content_corruption_backups` (réversible).

> Si le dry-run montre `0` partout : rien à corriger, tu peux sauter 7b.

---

## 8. Frontend (si déployé en même temps)

Le frontend est un **dépôt séparé** (`frontend_lms`), build local → upload cPanel.
Il appelle le path NON-versionné `/api/...` (conservé volontairement). Aucune
action backend requise. (Voir son propre runbook `DEPLOIEMENT_CPANEL.md` côté frontend.)

---

## Rollback (si quelque chose tourne mal)

1. **Code** :
   ```bash
   git log --oneline -5            # noter le commit précédent
   git reset --hard <commit_precedent>
   composer install --no-dev --optimize-autoloader
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
2. **Base de données** (si une migration a cassé quelque chose) :
   ```bash
   mysql -u <DB_USERNAME> -p <DB_DATABASE> < ~/backup-lms-AAAA-MM-JJ-HHMM.sql
   ```
3. Vérifier l'étape 6 à nouveau.

---

## Aide-mémoire des pièges (résumé en 5 lignes)

1. **`php artisan migrate --force`** — l'oublier = login 500 (incident 2026-06-20).
2. **`APP_DEBUG=false` + `APP_ENV=production`** dans le `.env` serveur (#1).
3. **`config:cache` APRÈS** avoir vérifié le `.env` (sinon ancienne config figée).
4. **`content:fix-corruption`** en dry-run d'abord, puis `--apply`.
5. **Jamais d'édition directe sur cPanel** — local → push → `git pull`.
