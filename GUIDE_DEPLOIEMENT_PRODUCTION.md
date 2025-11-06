# 🚀 Guide de Déploiement en Production - LMS KLASSCI

## ⚠️ MIGRATIONS MANQUANTES À EXÉCUTER

**IMPORTANT**: Avant de déployer, exécutez ces migrations en local :

```bash
php artisan migrate
```

Migrations en attente :
- `2025_10_27_172000_create_forum_tables`
- `2025_10_27_180245_create_notifications_table`

---

## 📋 Checklist Pré-déploiement

- [ ] Toutes les migrations sont exécutées en local
- [ ] Les tests passent correctement
- [ ] Les tokens API KLASSCI sont disponibles
- [ ] Les credentials de la base de données de production sont prêts
- [ ] Le serveur de production est configuré (PHP 8.1+, Composer, etc.)

---

## 🗄️ Option 1 : Déploiement avec BASE VIERGE (Recommandé)

### Sur le serveur de production :

```bash
# 1. Copier les fichiers du projet
# (via FTP, Git, ou autre)

# 2. Installer les dépendances
composer install --no-dev --optimize-autoloader

# 3. Configurer l'environnement
cp .env.production.example .env
# Éditer .env avec vos vraies valeurs

# 4. Générer la clé d'application
php artisan key:generate

# 5. Exécuter les migrations
php artisan migrate --force

# 6. Optimiser pour la production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Définir les permissions
chmod -R 755 storage bootstrap/cache
```

---

## 🗄️ Option 2 : Déploiement avec DONNÉES EXISTANTES

### Étape 1 : Export depuis développement (local)

```bash
# 1. Exécuter toutes les migrations manquantes
php artisan migrate

# 2. Exporter les données
php export_data.php

# 3. Un dossier database/exports/ sera créé avec tous les fichiers JSON
```

### Étape 2 : Transférer vers production

```bash
# Transférer ces fichiers/dossiers vers le serveur :
- Tout le code source
- Le dossier database/exports/
- Le fichier import_data.php
```

### Étape 3 : Import en production

```bash
# 1. Sur le serveur de production
composer install --no-dev --optimize-autoloader

# 2. Configurer .env
cp .env.production.example .env
# Éditer avec vos vraies valeurs

# 3. Générer la clé
php artisan key:generate

# 4. Créer la structure (migrations)
php artisan migrate --force

# 5. Importer les données
php import_data.php
# Taper "yes" pour confirmer

# 6. Optimiser
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Permissions
chmod -R 755 storage bootstrap/cache
```

---

## 🗄️ Option 3 : Export/Import SQLite Direct

Si vous voulez juste copier la base SQLite :

```bash
# En développement
cp database/database.sqlite database/database_backup.sqlite

# Transférer database_backup.sqlite vers le serveur
# Sur le serveur, renommer en database.sqlite
```

**⚠️ ATTENTION**: Cette méthode n'est recommandée QUE si vous restez en SQLite en production.
Pour MySQL/PostgreSQL, utilisez les Options 1 ou 2.

---

## 🔧 Configuration Production (.env)

### Variables critiques à modifier :

```env
APP_ENV=production
APP_DEBUG=false  # TRÈS IMPORTANT !
APP_URL=https://votre-domaine.com

# Pour MySQL (recommandé pour production)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nom_de_votre_base
DB_USERNAME=votre_utilisateur
DB_PASSWORD=mot_de_passe_securise

# Cache optimisé (si Redis disponible)
CACHE_STORE=redis
SESSION_DRIVER=redis

# Tokens API KLASSCI (OBLIGATOIRE)
KLASSCI_API_TOKEN=votre_vrai_token
KLASSCI_PRESENTATION_TOKEN=votre_token_presentation
KLASSCI_ESBTP_ABIDJAN_TOKEN=votre_token_abidjan
KLASSCI_ESBTP_YAKRO_TOKEN=votre_token_yakro

# Email (selon votre hébergeur)
MAIL_MAILER=smtp
MAIL_HOST=smtp.votre-domaine.com
MAIL_PORT=587
MAIL_USERNAME=email@domaine.com
MAIL_PASSWORD=mot_de_passe_email
```

---

## 🔒 Sécurité Production

### 1. Permissions fichiers

```bash
# Propriétaire correct (selon votre serveur)
chown -R www-data:www-data /path/to/lms-backend

# Permissions
find /path/to/lms-backend -type f -exec chmod 644 {} \;
find /path/to/lms-backend -type d -exec chmod 755 {} \;

# Dossiers critiques en écriture
chmod -R 775 storage bootstrap/cache
```

### 2. Fichiers sensibles

Assurez-vous que ces fichiers ne sont PAS accessibles via web :

```
.env
.env.production.example
composer.json
composer.lock
artisan
*.php (racine, sauf index.php public/)
```

### 3. Configuration serveur web

#### Apache (.htaccess dans public/) :

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>

# Bloquer accès aux fichiers sensibles
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>
```

#### Nginx :

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ /\.(?!well-known).* {
    deny all;
}
```

---

## 🧪 Tests Post-déploiement

### 1. Vérifications de base

```bash
# Tester la connexion à la base
php artisan tinker
>>> DB::connection()->getPdo();

# Vérifier les migrations
php artisan migrate:status

# Tester une requête simple
php artisan tinker
>>> \App\Models\User::count();
```

### 2. Tests API

```bash
# Test de santé
curl https://votre-domaine.com/api/health

# Test authentification
curl -X POST https://votre-domaine.com/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@test.com","password":"password"}'

# Test connexion KLASSCI
curl https://votre-domaine.com/api/klassci/test
```

### 3. Vérifier les logs

```bash
tail -f storage/logs/laravel.log
```

---

## 🐛 Dépannage

### Erreur "500 Internal Server Error"

```bash
# Vérifier les logs
tail -50 storage/logs/laravel.log

# Vérifier les permissions
ls -la storage/
ls -la bootstrap/cache/

# Recréer les caches
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

### Erreur de migration

```bash
# Voir le statut
php artisan migrate:status

# Rollback si nécessaire (ATTENTION: perte de données)
php artisan migrate:rollback

# Recommencer
php artisan migrate --force
```

### Problème de connexion KLASSCI API

```bash
# Tester la connexion
php artisan tinker
>>> Http::withToken(env('KLASSCI_API_TOKEN'))
       ->get(env('KLASSCI_API_URL') . '/classes');
```

---

## 📊 Maintenance

### Backup régulier

```bash
# Backup MySQL
mysqldump -u user -p database_name > backup_$(date +%Y%m%d).sql

# Backup SQLite
cp database/database.sqlite backups/database_$(date +%Y%m%d).sqlite

# Backup fichiers
tar -czf backup_files_$(date +%Y%m%d).tar.gz storage/app/public/
```

### Logs

```bash
# Nettoyer les vieux logs (ajouter au cron)
find storage/logs/ -name "*.log" -mtime +30 -delete
```

---

## 📞 Support

En cas de problème, vérifiez :

1. Les logs Laravel : `storage/logs/laravel.log`
2. Les logs du serveur web
3. Les variables d'environnement dans `.env`
4. Les permissions des fichiers
5. La connexion à l'API KLASSCI

---

## ✅ Checklist Post-déploiement

- [ ] L'application se charge correctement
- [ ] Les migrations sont toutes exécutées
- [ ] Les données sont bien importées (si Option 2)
- [ ] Les API KLASSCI répondent correctement
- [ ] Les logs ne montrent pas d'erreurs
- [ ] Les permissions sont correctes
- [ ] Le cache est activé
- [ ] HTTPS est configuré
- [ ] Les backups automatiques sont en place
- [ ] Les utilisateurs peuvent se connecter
- [ ] Les séances visio fonctionnent

---

**Date de création**: 2025-11-02
**Version**: 1.0
