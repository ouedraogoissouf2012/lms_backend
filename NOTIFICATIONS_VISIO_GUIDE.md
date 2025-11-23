# Guide Complet: Système de Notifications pour les Séances Visio

## Vue d'ensemble

Le système de notifications pour les séances de visioconférence fonctionne désormais de manière **automatique** et détecte les séances créées directement dans Klassci.

## ✅ Fonctionnalités Implémentées

### 1. **Détection Automatique**
Lorsqu'une séance avec visio est créée dans Klassci, le système LMS la détecte automatiquement lors du prochain chargement de la page des séances par l'enseignant.

### 2. **Synchronisation des Classes**
- Les classes sont automatiquement synchronisées depuis Klassci vers la base locale
- Les étudiants sont créés et inscrits dans les classes
- Permet l'envoi de notifications aux bons destinataires

### 3. **Envoi Automatique de Notifications**
Deux types de notifications:
- **visio_scheduled**: Envoyée aux étudiants quand une visio est programmée
- **visio_starting**: Envoyée aux étudiants ET à l'enseignant quand la visio démarre

## 📋 Workflow Complet

```
┌────────────────────────────────────────────┐
│ 1. Séance créée dans Klassci avec visio   │
│    (directement depuis l'interface Klassci)│
└──────────────┬─────────────────────────────┘
               ▼
┌────────────────────────────────────────────┐
│ 2. Enseignant ouvre la page des séances   │
│    dans le LMS frontend                    │
└──────────────┬─────────────────────────────┘
               ▼
┌────────────────────────────────────────────┐
│ 3. Frontend appelle:                       │
│    GET /api/lms/seances/my-teaching        │
└──────────────┬─────────────────────────────┘
               ▼
┌────────────────────────────────────────────┐
│ 4. Backend récupère les séances de Klassci│
│    (via KlassciProxyService)               │
└──────────────┬─────────────────────────────┘
               ▼
┌────────────────────────────────────────────┐
│ 5. Détection automatique:                  │
│    - Séance a visio_enabled = true ?       │
│    - Pas encore dans table locale seances ?│
│    → OUI: Déclencher synchro et notifs     │
└──────────────┬─────────────────────────────┘
               ▼
┌────────────────────────────────────────────┐
│ 6. ClasseSyncService synchronise:          │
│    - Récupère détails de la classe         │
│    - Crée/met à jour classe locale         │
│    - Crée les étudiants manquants          │
│    - Crée les inscriptions (classe_etudiant)│
└──────────────┬─────────────────────────────┘
               ▼
┌────────────────────────────────────────────┐
│ 7. Création entrée locale dans seances:   │
│    - klassci_seance_id                     │
│    - visio_enabled = true                  │
│    - visio_status = 'programmee'           │
│    - visio_room_id généré                  │
└──────────────┬─────────────────────────────┘
               ▼
┌────────────────────────────────────────────┐
│ 8. NotificationService envoie:             │
│    - Notifications aux étudiants actifs    │
│    - 1 notification par étudiant           │
│    - Type: visio_scheduled                 │
└────────────────────────────────────────────┘
```

## 🔧 Architecture Technique

### Services Créés

#### 1. **ClasseSyncService** (`app/Services/ClasseSyncService.php`)
Service responsable de la synchronisation des classes depuis Klassci.

**Méthodes principales:**
- `syncUserClasses(string $klassciToken, string $userRole)`: Synchronise toutes les classes d'un utilisateur
- `syncClasseById(int $klassciClasseId, string $klassciToken)`: Synchronise une classe spécifique
- `syncClasse(array $klasseData)`: Crée/met à jour une classe locale
- `syncClasseStudents(Classe $classe, array $etudiantsData)`: Synchronise les étudiants

**Points importants:**
- Gère les variations de l'API Klassci (name/nom, places_totales/places, etc.)
- Stocke les données complètes dans `klassci_data` (JSON)
- Met à jour `last_klassci_sync` à chaque synchronisation
- Crée automatiquement les utilisateurs étudiants manquants

#### 2. **NotificationService** (amélioré)
Service responsable de l'envoi des notifications.

**Nouvelles méthodes:**
- `notifyVisioScheduled(int $seanceId, array $seanceData)`: Notifie les étudiants qu'une visio est programmée
- `notifyVisioStarting(int $seanceId, array $seanceData)`: Notifie étudiants + enseignant au démarrage

**Fonctionnement:**
1. Récupère la classe locale via `klassci_classe_id`
2. Récupère les étudiants actifs de cette classe
3. Crée une notification pour chaque étudiant
4. Retourne le nombre de notifications envoyées

### Modifications dans LMSDataController

#### 1. **Constructeur** (lignes 159-165)
Injection du `ClasseSyncService`:
```php
public function __construct(
    private KlassciProxyService $klassciService,
    private NotificationService $notificationService,
    private ClasseSyncService $classeSyncService
) {}
```

#### 2. **myTeachingSeances()** (lignes 2196-2242)
Détection automatique des séances Klassci avec visio:

```php
// Si la séance a visio_enabled dans Klassci mais pas encore notifiée dans LMS
if (!$visioData && isset($seance['visio_enabled']) && $seance['visio_enabled']) {
    // Synchroniser la classe
    if (isset($seance['classe']['id'])) {
        $this->classeSyncService->syncClasseById(
            $seance['classe']['id'],
            $klassciToken
        );
    }

    // Créer l'entrée locale
    $visioData = \App\Models\Seance::create([...]);

    // Envoyer les notifications
    $notificationsSent = $this->notificationService->notifyVisioScheduled(...);
}
```

#### 3. **activateVisio()** (lignes 2588-2636)
Synchronise la classe et envoie des notifications lors de l'activation manuelle depuis le LMS.

#### 4. **startVisio()** (lignes 2746-2783)
Synchronise la classe et envoie des notifications au démarrage de la visio.

### Modifications dans AuthController

**Login** (lignes 125-172)
Synchronisation automatique des classes à la connexion:
```php
$syncStats = $this->classeSyncService->syncUserClasses($klassciToken, $localUser->role);
```

Retourne des statistiques dans la réponse:
```json
{
  "meta": {
    "classes_sync": {
      "classes_created": 2,
      "students_synced": 5,
      "enrollments_created": 5
    }
  }
}
```

## 📊 État Actuel du Système

D'après le dernier test:

```
✅ Classes synchronisées: 2
✅ Étudiants: 2
✅ Inscriptions actives: 2
✅ Séances avec visio: 2
✅ Notifications envoyées: 4
```

**Classes:**
- B2 COM (Klassci ID: 1) - 1 étudiant actif
- B3 COM (Klassci ID: 2) - 1 étudiant actif

**Notifications:**
- 1 × visio_scheduled (à un étudiant)
- 3 × visio_starting (2 à l'enseignant, 1 à un étudiant)

## 🧪 Comment Tester

### Test 1: Créer une séance dans Klassci

1. **Créer une séance dans Klassci:**
   - Connectez-vous à Klassci
   - Créez une nouvelle séance pour une matière
   - **Activez la visioconférence**
   - Enregistrez

2. **Vérifier dans le LMS:**
   - Connectez-vous au LMS frontend en tant qu'enseignant
   - Allez à la page des séances
   - La page devrait charger automatiquement
   - Vérifiez les logs backend:
     ```bash
     tail -f storage/logs/laravel.log | grep "Séance Klassci"
     ```

3. **Résultat attendu:**
   - Log: "Séance Klassci avec visio détectée - Notifications envoyées"
   - Les étudiants de la classe reçoivent une notification
   - La séance apparaît avec l'icône visio dans le LMS

### Test 2: Vérifier les notifications

**Backend:**
```bash
php test_final_notification_system.php
```

**Requête API:**
```bash
# En tant qu'étudiant
GET /api/notifications
Authorization: Bearer <student_token>
```

**Réponse attendue:**
```json
{
  "data": [
    {
      "id": 1,
      "type": "visio_scheduled",
      "title": "Visioconférence programmée",
      "message": "Une visioconférence a été programmée en Marketing digital avec BEDE ABEL TEST.",
      "data": {
        "seance_id": 50,
        "matiere": "Marketing digital",
        "enseignant": "BEDE ABEL TEST"
      },
      "is_read": false,
      "created_at": "2025-11-18T11:09:36.000000Z"
    }
  ]
}
```

### Test 3: Script de diagnostic

```bash
# Vérifier l'état complet du système
php test_final_notification_system.php

# Vérifier la synchronisation
php test_sync_and_notifications.php

# Tester la détection auto
php test_auto_detection_visio.php
```

## 🔍 Logs et Debugging

### Logs importants à surveiller:

```bash
# Détection auto
tail -f storage/logs/laravel.log | grep "Séance Klassci avec visio détectée"

# Synchronisation des classes
tail -f storage/logs/laravel.log | grep "Classe synchronisée"

# Envoi de notifications
tail -f storage/logs/laravel.log | grep "Notifications visio"
```

### Messages de log clés:

**Succès de détection:**
```
Séance Klassci avec visio détectée - Notifications envoyées
{
  "seance_id": 50,
  "notifications_sent": 3
}
```

**Synchronisation classe:**
```
Classe synchronisée
{
  "classe_id": 1,
  "libelle": "B2 COM"
}
```

**Erreurs possibles:**
```
Erreur détection auto visio Klassci
{
  "seance_id": 50,
  "error": "..."
}
```

## 🐛 Dépannage

### Problème: Aucune notification n'est envoyée

**Vérifications:**

1. **La classe est-elle synchronisée?**
   ```bash
   php -r "
   require 'vendor/autoload.php';
   \$app = require 'bootstrap/app.php';
   \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
   use App\Models\Classe;
   \$classe = Classe::where('klassci_id', 1)->first();
   echo \$classe ? 'Classe trouvée: ' . \$classe->libelle : 'Classe non trouvée';
   "
   ```

2. **Y a-t-il des étudiants actifs?**
   ```bash
   php -r "
   require 'vendor/autoload.php';
   \$app = require 'bootstrap/app.php';
   \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
   use Illuminate\Support\Facades\DB;
   \$count = DB::table('classe_etudiant')
       ->where('classe_id', 1)
       ->where('statut', 'actif')
       ->count();
   echo 'Étudiants actifs: ' . \$count;
   "
   ```

3. **La séance a-t-elle visio_enabled?**
   ```bash
   php -r "
   require 'vendor/autoload.php';
   \$app = require 'bootstrap/app.php';
   \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
   use Illuminate\Support\Facades\DB;
   \$seance = DB::table('seances')->find(3);
   echo 'Visio enabled: ' . (\$seance->visio_enabled ? 'OUI' : 'NON');
   "
   ```

### Problème: La détection auto ne se déclenche pas

**Causes possibles:**

1. **L'entrée existe déjà localement**
   - La détection ne se déclenche que si `!$visioData`
   - Vérifiez: `SELECT * FROM seances WHERE klassci_seance_id = <ID>;`

2. **La séance n'a pas visio_enabled dans Klassci**
   - Vérifiez la réponse Klassci
   - Créez un script de debug pour voir les données brutes

3. **L'API Klassci ne retourne pas la séance**
   - Vérifiez que la séance est bien dans les `seances_programmees`
   - Testez avec: `php debug_klassci_classes.php`

## 📝 Modifications de la Base de Données

### Table: `classes`

Colonnes utilisées:
- `klassci_id` (unique): ID de la classe dans Klassci
- `libelle`: Nom de la classe
- `code`: Code de la classe
- `effectif`: Effectif maximum
- `filiere_id`: ID de la filière (foreign key)
- `niveau_id`: ID du niveau (foreign key)
- `klassci_data` (JSON): Données complètes depuis Klassci
- `last_klassci_sync`: Date de dernière synchronisation

### Table: `classe_etudiant` (pivot)

Colonnes:
- `classe_id`: ID de la classe locale
- `user_id`: ID de l'étudiant
- `statut`: 'actif' ou 'inactif'
- `date_inscription`: Date d'inscription
- `annee_universitaire_id`: Année universitaire

### Table: `seances`

Colonnes importantes:
- `klassci_seance_id`: ID de la séance dans Klassci
- `klassci_classe_id`: ID de la classe dans Klassci
- `visio_enabled`: Boolean
- `visio_status`: 'programmee', 'active', 'terminee'
- `visio_room_id`: ID de la salle Jitsi

### Table: `notifications`

Colonnes:
- `user_id`: Destinataire
- `type`: 'visio_scheduled' ou 'visio_starting'
- `title`: Titre de la notification
- `message`: Message
- `data` (JSON): Données additionnelles (seance_id, matiere, etc.)
- `is_read`: Boolean

## 🚀 Évolutions Futures Possibles

1. **Webhook Klassci**: Recevoir des notifications push au lieu de polling
2. **Notifications en temps réel**: WebSockets pour notifier instantanément
3. **Emails**: Envoyer aussi des emails aux étudiants
4. **SMS**: Pour les séances imminentes
5. **Rappels**: Notification 10 minutes avant le début
6. **Historique**: Garder trace de toutes les notifications envoyées

## 📚 Fichiers de Test Disponibles

- `test_final_notification_system.php`: Test complet du système
- `test_sync_and_notifications.php`: Test synchronisation + notifications
- `test_auto_detection_visio.php`: Test détection automatique
- `test_seance_notifications.php`: Test envoi notifications
- `debug_klassci_classes.php`: Debug API Klassci

## ✅ Checklist de Déploiement

Avant de déployer en production:

- [ ] Tester la création de séance dans Klassci
- [ ] Vérifier que les notifications arrivent aux étudiants
- [ ] Tester la synchronisation des classes
- [ ] Vérifier les logs pour détecter les erreurs
- [ ] Tester avec plusieurs classes
- [ ] Tester avec plusieurs étudiants
- [ ] Vérifier les performances (temps de réponse API)
- [ ] Documenter pour l'équipe
- [ ] Former les enseignants à l'utilisation

## 📞 Support

En cas de problème:

1. Vérifiez les logs: `tail -f storage/logs/laravel.log`
2. Lancez les scripts de test
3. Vérifiez l'état de la base de données
4. Consultez cette documentation

---

**Date de création:** 18 novembre 2025
**Version:** 1.0
**Auteur:** Claude Code
**Status:** ✅ Opérationnel
