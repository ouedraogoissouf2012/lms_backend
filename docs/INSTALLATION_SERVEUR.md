# 🚀 Installation Serveur - Guide Rapide

## Checklist des Logiciels Requis

### ✅ OBLIGATOIRE

#### LibreOffice
Pour convertir PowerPoint (.pptx) et Word (.docx)

**Ubuntu/Debian :**
```bash
sudo apt-get update
sudo apt-get install -y libreoffice libreoffice-writer libreoffice-impress
```

**CentOS/RHEL :**
```bash
sudo yum install -y libreoffice libreoffice-writer libreoffice-impress
```

**Windows :**
- Télécharger : https://www.libreoffice.org/download/
- Installer dans `C:\Program Files\LibreOffice`

**Vérification :**
```bash
soffice --version
```

---

### ⭐ RECOMMANDÉ

#### ImageMagick + Extension PHP Imagick
Pour convertir PDF et PowerPoint en images de haute qualité

**Ubuntu/Debian :**
```bash
sudo apt-get install -y imagemagick php-imagick
sudo systemctl restart apache2  # ou php-fpm
```

**CentOS/RHEL :**
```bash
sudo yum install -y ImageMagick ImageMagick-devel php-imagick
sudo systemctl restart httpd  # ou php-fpm
```

**Windows :**
1. Télécharger ImageMagick : https://imagemagick.org/script/download.php#windows
2. Installer avec l'option "Install legacy utilities"
3. Installer extension PHP Imagick (PECL ou DLL)

**Vérification :**
```bash
convert --version
php -m | grep imagick
```

---

### 📦 OPTIONNEL (Fallback)

#### Ghostscript
Utilisé si Imagick n'est pas disponible

**Ubuntu/Debian :**
```bash
sudo apt-get install -y ghostscript
```

**CentOS/RHEL :**
```bash
sudo yum install -y ghostscript
```

**Windows :**
- Télécharger : https://ghostscript.com/releases/gsdnld.html

**Vérification :**
```bash
gs --version
```

---

## Configuration PHP

### Modifier php.ini

Fichier à modifier :
- **Ubuntu/Debian :** `/etc/php/8.2/apache2/php.ini` ou `/etc/php/8.2/fpm/php.ini`
- **CentOS/RHEL :** `/etc/php.ini`
- **Windows :** `C:\php\php.ini` ou `C:\xampp\php\php.ini`

**Modifications requises :**
```ini
; Augmenter les limites d'upload
upload_max_filesize = 100M
post_max_size = 100M

; Augmenter le temps d'exécution
max_execution_time = 300
max_input_time = 300

; Augmenter la mémoire
memory_limit = 512M

; Activer Imagick (si installé)
extension=imagick

; GD déjà activé par défaut
extension=gd
```

### Redémarrer le serveur web

**Apache :**
```bash
sudo systemctl restart apache2
```

**Nginx + PHP-FPM :**
```bash
sudo systemctl restart php8.2-fpm
sudo systemctl restart nginx
```

**Windows (XAMPP) :**
- Arrêter et redémarrer Apache depuis le panneau XAMPP

---

## Permissions Dossiers (Linux)

```bash
cd /var/www/html/lms-backend

# Donner les permissions
chmod -R 775 storage/app/public
chmod -R 775 storage/logs
chmod -R 775 bootstrap/cache

# Changer le propriétaire (Apache/Nginx)
sudo chown -R www-data:www-data storage
sudo chown -R www-data:www-data bootstrap/cache

# Créer le lien symbolique
php artisan storage:link
```

---

## Tester la Configuration

Exécuter le script de test :

```bash
cd lms-backend
php test_conversion.php
```

**Résultat attendu :**
```
✅ LibreOffice installé et fonctionnel
✅ Imagick complètement fonctionnel
✅ Ghostscript installé
✅ GD Library disponible
✅ Configuration PHP correcte
✅ Dossiers accessibles en écriture

🎉 CONFIGURATION PARFAITE!
```

---

## Installation Complète (Ubuntu 22.04)

Script d'installation automatique :

```bash
#!/bin/bash

# 1. Mettre à jour le système
sudo apt-get update
sudo apt-get upgrade -y

# 2. Installer LibreOffice (OBLIGATOIRE)
sudo apt-get install -y libreoffice libreoffice-writer libreoffice-impress

# 3. Installer ImageMagick + Imagick (RECOMMANDÉ)
sudo apt-get install -y imagemagick php-imagick

# 4. Installer Ghostscript (FALLBACK)
sudo apt-get install -y ghostscript

# 5. Installer GD (normalement déjà présent)
sudo apt-get install -y php-gd

# 6. Modifier php.ini
sudo sed -i 's/upload_max_filesize = .*/upload_max_filesize = 100M/' /etc/php/8.2/apache2/php.ini
sudo sed -i 's/post_max_size = .*/post_max_size = 100M/' /etc/php/8.2/apache2/php.ini
sudo sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/8.2/apache2/php.ini
sudo sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/8.2/apache2/php.ini

# 7. Redémarrer Apache
sudo systemctl restart apache2

# 8. Tester
php /var/www/html/lms-backend/test_conversion.php
```

---

## Dépannage Rapide

### LibreOffice ne fonctionne pas

```bash
# Vérifier installation
which soffice
soffice --version

# Tester conversion manuelle
soffice --headless --convert-to pdf test.docx --outdir /tmp
```

### Imagick non chargé

```bash
# Vérifier extension
php -m | grep imagick

# Réinstaller
sudo apt-get remove php-imagick
sudo apt-get install php-imagick
sudo systemctl restart apache2
```

### Permissions refusées

```bash
# Vérifier propriétaire
ls -la storage/

# Corriger
sudo chown -R www-data:www-data storage/
chmod -R 775 storage/
```

---

## Résumé des Commandes de Vérification

```bash
# LibreOffice
soffice --version

# ImageMagick
convert --version

# Imagick PHP
php -m | grep imagick

# Ghostscript
gs --version

# GD PHP
php -m | grep gd

# Limites PHP
php -i | grep upload_max_filesize
php -i | grep post_max_size
php -i | grep max_execution_time
php -i | grep memory_limit

# Permissions
ls -la storage/app/public
ls -la storage/logs
```

---

**Pour plus de détails, consultez `GUIDE_CREATION_LECONS.md`**
