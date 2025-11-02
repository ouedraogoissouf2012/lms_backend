# 📚 Guide Complet - Création de Leçons dans le LMS

## Table des matières
1. [Prérequis Serveur](#prérequis-serveur)
2. [Installation des Dépendances](#installation-des-dépendances)
3. [Configuration du Système](#configuration-du-système)
4. [Création d'une Leçon - Guide Utilisateur](#création-dune-leçon---guide-utilisateur)
5. [Types de Contenu Supportés](#types-de-contenu-supportés)
6. [Gestion des Chapitres](#gestion-des-chapitres)
7. [Publication et Partage](#publication-et-partage)
8. [Dépannage](#dépannage)

---

## Prérequis Serveur

### 🖥️ Logiciels Requis sur le Serveur

Pour que toutes les fonctionnalités de création de leçons fonctionnent correctement, vous devez installer les logiciels suivants sur votre serveur :

#### 1. **LibreOffice** (OBLIGATOIRE)
Nécessaire pour convertir les fichiers PowerPoint et Word.

**Installation :**

**Windows :**
```bash
# Télécharger depuis https://www.libreoffice.org/download/
# Installer dans C:\Program Files\LibreOffice
# Ou utiliser Chocolatey :
choco install libreoffice
```

**Linux (Ubuntu/Debian) :**
```bash
sudo apt-get update
sudo apt-get install -y libreoffice libreoffice-writer libreoffice-impress
```

**Linux (CentOS/RHEL) :**
```bash
sudo yum install -y libreoffice libreoffice-writer libreoffice-impress
```

**macOS :**
```bash
brew install --cask libreoffice
```

**Vérification :**
```bash
# Windows
"C:\Program Files\LibreOffice\program\soffice.exe" --version

# Linux/Mac
soffice --version
```

---

#### 2. **Ghostscript** (OPTIONNEL - Pour PDF sans Imagick)
Nécessaire si ImageMagick n'est pas disponible pour convertir les PDF en images.

**Installation :**

**Windows :**
```bash
# Télécharger depuis https://ghostscript.com/releases/gsdnld.html
# Installer dans C:\Program Files\gs\
```

**Linux (Ubuntu/Debian) :**
```bash
sudo apt-get install -y ghostscript
```

**Linux (CentOS/RHEL) :**
```bash
sudo yum install -y ghostscript
```

**macOS :**
```bash
brew install ghostscript
```

**Vérification :**
```bash
# Windows
gswin64c --version

# Linux/Mac
gs --version
```

---

#### 3. **ImageMagick avec Imagick PHP Extension** (RECOMMANDÉ)
Meilleure qualité pour convertir les PDF et PowerPoint en images.

**Installation :**

**Windows :**
```bash
# 1. Télécharger ImageMagick depuis https://imagemagick.org/script/download.php#windows
# 2. Installer avec "Install legacy utilities (e.g. convert)"
# 3. Installer l'extension PHP Imagick via PECL ou DLL
```

**Linux (Ubuntu/Debian) :**
```bash
sudo apt-get update
sudo apt-get install -y imagemagick php-imagick
sudo systemctl restart apache2  # ou php-fpm
```

**Linux (CentOS/RHEL) :**
```bash
sudo yum install -y ImageMagick ImageMagick-devel
sudo yum install -y php-imagick
sudo systemctl restart httpd  # ou php-fpm
```

**macOS :**
```bash
brew install imagemagick
pecl install imagick
```

**Vérification :**
```bash
# Commande système
convert --version

# Extension PHP
php -m | grep imagick
```

**Configuration PHP (php.ini) :**
```ini
extension=imagick

# Augmenter les limites pour les gros fichiers
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
memory_limit = 512M
```

---

#### 4. **GD Library** (Fallback - Généralement déjà installé)
Extension PHP de base pour traiter les images.

**Installation :**

**Linux (Ubuntu/Debian) :**
```bash
sudo apt-get install -y php-gd
```

**Vérification :**
```bash
php -m | grep gd
```

---

### 📋 Checklist Installation Serveur

| Logiciel | Statut | Commande de vérification |
|----------|--------|--------------------------|
| LibreOffice | ⬜ | `soffice --version` |
| Ghostscript | ⬜ | `gs --version` |
| ImageMagick | ⬜ | `convert --version` |
| PHP Imagick | ⬜ | `php -m \| grep imagick` |
| PHP GD | ⬜ | `php -m \| grep gd` |

---

## Installation des Dépendances

### Backend (Laravel)

```bash
cd lms-backend
composer install
```

**Packages utilisés :**
- `barryvdh/laravel-dompdf` : Génération de PDF
- Laravel 12 avec support SQLite/MySQL

---

### Frontend (Vue.js)

```bash
cd lms-frontend
npm install
```

**Packages TipTap (Éditeur de texte riche) :**
- `@tiptap/vue-3` : Composant Vue
- `@tiptap/starter-kit` : Extensions de base
- `@tiptap/extension-*` : Extensions avancées (tables, liens, images, etc.)

---

## Configuration du Système

### 1. Configuration Laravel (.env)

```env
# Stockage des fichiers
FILESYSTEM_DISK=public

# Augmenter les limites
UPLOAD_MAX_FILESIZE=100M
POST_MAX_SIZE=100M

# Cache (optionnel)
CACHE_DRIVER=database
```

### 2. Permissions des Dossiers

```bash
# Linux/Mac
chmod -R 775 storage/app/public
chmod -R 775 storage/logs
chown -R www-data:www-data storage

# Créer le lien symbolique
php artisan storage:link
```

### 3. Configuration PHP (php.ini)

```ini
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
memory_limit = 512M
max_input_time = 300

# Pour PDF avec Imagick
extension=imagick
```

### 4. Tester la Configuration

Créer un fichier `test_conversion.php` à la racine du backend :

```php
<?php
echo "=== Test Configuration Serveur ===\n\n";

// 1. Vérifier LibreOffice
echo "📊 LibreOffice:\n";
$paths = [
    'C:/Program Files/LibreOffice/program/soffice.exe',
    '/usr/bin/soffice',
    '/usr/local/bin/soffice'
];
foreach ($paths as $path) {
    if (file_exists($path)) {
        echo "✅ Trouvé: $path\n";
        exec("\"$path\" --version", $output);
        echo "   Version: " . implode("\n", $output) . "\n";
        break;
    }
}

// 2. Vérifier Imagick
echo "\n🖼️ ImageMagick (Imagick):\n";
if (extension_loaded('imagick')) {
    echo "✅ Extension PHP Imagick chargée\n";
    $imagick = new Imagick();
    echo "   Version: " . $imagick->getVersion()['versionString'] . "\n";
} else {
    echo "❌ Extension Imagick non disponible\n";
}

// 3. Vérifier GD
echo "\n🎨 GD Library:\n";
if (extension_loaded('gd')) {
    echo "✅ Extension PHP GD chargée\n";
    echo "   Version: " . gd_info()['GD Version'] . "\n";
} else {
    echo "❌ Extension GD non disponible\n";
}

// 4. Vérifier Ghostscript
echo "\n👻 Ghostscript:\n";
$gsCommands = ['gswin64c', 'gs'];
foreach ($gsCommands as $cmd) {
    exec("$cmd --version 2>&1", $gsOutput, $returnCode);
    if ($returnCode === 0) {
        echo "✅ Ghostscript trouvé: $cmd\n";
        echo "   Version: " . $gsOutput[0] . "\n";
        break;
    }
}

// 5. Vérifier limites PHP
echo "\n⚙️ Configuration PHP:\n";
echo "Upload max: " . ini_get('upload_max_filesize') . "\n";
echo "Post max: " . ini_get('post_max_size') . "\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo "Max execution time: " . ini_get('max_execution_time') . "s\n";

echo "\n=== Test terminé ===\n";
```

**Exécuter le test :**
```bash
php test_conversion.php
```

---

## Création d'une Leçon - Guide Utilisateur

### 📌 Vue d'ensemble

Le système de création de leçons se fait en **2 étapes** :
1. **Créer la leçon** (métadonnées) depuis l'onglet **Matières**
2. **Ajouter les chapitres** avec différents types de contenu

---

### 🎯 Étape 1 : Créer une Nouvelle Leçon

#### Accès à la création
1. Connectez-vous en tant qu'**enseignant**
2. Allez dans **Matières** (menu de gauche)
3. Sélectionnez une **matière**
4. Cliquez sur **"+ Créer une leçon"**

#### Formulaire de création

| Champ | Description | Requis |
|-------|-------------|--------|
| **Titre** | Nom de la leçon (ex: "Introduction à la POO") | ✅ Oui |
| **Description** | Résumé du contenu de la leçon | ❌ Non |
| **Classe** | Classe concernée par cette leçon | ✅ Oui |
| **Niveau de difficulté** | Débutant / Intermédiaire / Avancé | ❌ Non |
| **Durée estimée** | En minutes (ex: 60) | ❌ Non |
| **Prérequis** | Connaissances nécessaires avant la leçon | ❌ Non |
| **Objectifs pédagogiques** | Ce que l'étudiant doit apprendre | ❌ Non |

**Exemple de leçon :**
```
Titre: Introduction à la Programmation Orientée Objet
Description: Découvrez les concepts fondamentaux de la POO en PHP
Classe: L3 Informatique - Groupe A
Niveau: Intermédiaire
Durée: 90 minutes
Prérequis: Bases de PHP (variables, fonctions, tableaux)
Objectifs:
- Comprendre les concepts de classe et objet
- Savoir créer et instancier une classe
- Utiliser les propriétés et méthodes
```

#### Actions après création
Après avoir cliqué sur **"Créer"**, vous êtes automatiquement redirigé vers la **gestion des chapitres**.

---

### 📖 Étape 2 : Ajouter des Chapitres

#### Qu'est-ce qu'un chapitre ?

Un **chapitre** est une section de votre leçon qui contient un type de contenu spécifique :
- Texte enrichi
- Vidéo YouTube/Vimeo
- Document PowerPoint (converti en slides)
- Document Word (converti en HTML)
- PDF (converti en images)
- Lien externe

#### Créer un chapitre

1. Cliquez sur **"+ Ajouter un chapitre"**
2. Remplissez le formulaire :

| Champ | Description |
|-------|-------------|
| **Titre du chapitre** | Ex: "1. Les Classes en PHP" |
| **Type de contenu** | Sélectionnez le type (voir section suivante) |
| **Contenu** | Selon le type choisi |

3. Cliquez sur **"Créer"** ou **"Enregistrer"**

---

## Types de Contenu Supportés

### 1. 📝 Texte / Markdown

**Description :** Éditeur de texte riche (TipTap) avec support Markdown.

**Fonctionnalités :**
- ✅ Gras, Italique, Souligné, Barré
- ✅ Titres (H1, H2, H3)
- ✅ Listes à puces et numérotées
- ✅ Tableaux
- ✅ Liens hypertextes
- ✅ Images (upload ou URL)
- ✅ Couleurs de texte et surlignage
- ✅ Alignement (gauche, centre, droite, justifié)
- ✅ Polices de caractères
- ✅ Citations
- ✅ Code source (blocs de code)
- ✅ Listes de tâches (todo lists)

**Utilisation :**
1. Sélectionnez **"Texte / Markdown"**
2. Rédigez votre contenu dans l'éditeur
3. Utilisez la barre d'outils pour formater
4. Cliquez sur **"Mode étendu"** pour affichage plein écran

---

### 2. 🎥 Vidéo (YouTube, Vimeo)

**Description :** Intégration de vidéos depuis YouTube ou Vimeo.

**Formats d'URL supportés :**
- YouTube : `https://www.youtube.com/watch?v=VIDEO_ID`
- YouTube court : `https://youtu.be/VIDEO_ID`
- Vimeo : `https://vimeo.com/VIDEO_ID`

**Utilisation :**
1. Sélectionnez **"Vidéo (YouTube, Vimeo)"**
2. Collez l'URL de la vidéo
3. Cochez **"Lecture automatique"** (optionnel)
4. Enregistrez

---

### 3. 📊 PowerPoint (Upload .pptx)

**Description :** Upload de présentation PowerPoint convertie automatiquement en images.

**Formats supportés :** `.pptx`, `.ppt`
**Taille max :** 30 MB
**Conversion :** PowerPoint → PDF → Images PNG

**Utilisation :**
1. Sélectionnez **"PowerPoint (upload .pptx)"**
2. Cliquez sur **"◈ Ajouter un média"**
3. Sélectionnez votre fichier `.pptx`
4. Attendez la conversion (peut prendre 30s-2min)
5. Enregistrez

**Processus de conversion :**
```
fichier.pptx
   ↓ (LibreOffice)
fichier.pdf
   ↓ (Imagick ou Ghostscript)
slide_001.png, slide_002.png, ...
```

**Résultat :**
- Chaque slide devient une image PNG
- Navigation par slides pour les étudiants
- Qualité optimisée (1920px max width)

**Limites :**
- Animations PowerPoint non conservées
- Transitions non conservées
- Résultat statique (images)

---

### 4. 📄 Document Word (Upload .docx)

**Description :** Upload de document Word converti en HTML.

**Formats supportés :** `.docx`, `.doc`
**Taille max :** 30 MB
**Conversion :** Word → HTML (via LibreOffice)

**Utilisation :**
1. Sélectionnez **"Document Word (upload .docx)"**
2. Cliquez sur **"◈ Ajouter un média"**
3. Sélectionnez votre fichier `.docx`
4. Attendez la conversion
5. Enregistrez

**Éléments conservés :**
- ✅ Texte et formatage de base (gras, italique)
- ✅ Titres (H1, H2, H3)
- ✅ Listes
- ✅ Tableaux (basique)

**Éléments perdus :**
- ❌ Mises en page complexes
- ❌ Images embarquées (à vérifier)
- ❌ Macros et objets OLE

**Recommandation :** Pour les documents complexes, privilégiez le PDF.

---

### 5. 📑 PDF (Upload .pdf)

**Description :** Upload de document PDF converti en images.

**Format supporté :** `.pdf`
**Taille max :** 30 MB
**Conversion :** PDF → Images PNG

**Résultat :**
- Chaque page devient une image PNG
- Navigation page par page
- Qualité haute résolution (150 DPI)

---

### 6. 🔗 Lien Externe

**Description :** Redirection vers une ressource externe.

**Exemples :**
- Documentation officielle
- Article de blog
- Site web de référence

---

## Gestion des Chapitres

### Visualiser les Chapitres

**Mode Consultation :**
- Depuis **Leçons** → Cliquez sur **"Voir les chapitres"**
- 👁️ Consultation uniquement (pas de modification)

**Mode Édition :**
- Depuis **Matières** → Sélectionnez la matière → **"Modifier la leçon"**
- ✏️ Modification complète

---

## Publication et Partage

### Statuts de Leçon

| Statut | Description | Visible par étudiants |
|--------|-------------|----------------------|
| **Brouillon** | En cours de création | ❌ Non |
| **Publiée** | Terminée et disponible | ✅ Oui |
| **Archivée** | Masquée mais conservée | ❌ Non |

### Publier une Leçon

1. Vérifiez que tous vos chapitres sont prêts
2. Cliquez sur **"Publier la leçon"**
3. Notification envoyée aux étudiants

---

## Dépannage

### ❌ Erreur : "LibreOffice n'est pas installé"

**Solution :** Installez LibreOffice (voir Prérequis Serveur)

### ❌ Upload bloqué à 8MB

**Solution :** Modifiez php.ini :
```ini
upload_max_filesize = 100M
post_max_size = 100M
```

### ❌ Timeout lors de la conversion

**Solution :**
```ini
max_execution_time = 300
```

---

## Bonnes Pratiques

### 📌 Choix du Format

| Besoin | Format recommandé |
|--------|-------------------|
| Cours théorique simple | Texte / Markdown |
| Présentation avec slides | PowerPoint ou PDF |
| Tutoriel vidéo | Vidéo YouTube |
| Document formaté complexe | PDF |

---

**Dernière mise à jour :** 2025-01-01
**Version du LMS :** 1.0
