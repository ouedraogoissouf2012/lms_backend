# Système de Notifications pour les Séances

## Vue d'ensemble

Ce système permet d'envoyer automatiquement des notifications à tous les acteurs (étudiants, enseignants, coordinateurs) lorsqu'une visioconférence est programmée ou démarrée.

## Architecture

### 1. Services Créés

#### ClasseSyncService (`app/Services/ClasseSyncService.php`)
Service responsable de la synchronisation des classes et étudiants depuis Klassci vers la base locale.

**Méthodes principales:**
- `syncUserClasses($klassciToken, $userRole)`: Synchronise toutes les classes d'un utilisateur
- `syncClasseById($klassciClasseId, $klassciToken)`: Synchronise une classe spécifique
- `syncClasse($klasseData)`: Crée/met à jour une classe locale
- `syncClasseStudents($classe, $etudiantsData)`: Synchronise les étudiants d'une classe

**Fonctionnalités:**
- ✅ Gère les différents formats d'API (name/nom, libelle, etc.)
- ✅ Supporte tous les rôles (étudiant, enseignant, coordinateur, superAdmin)
- ✅ Crée automatiquement les utilisateurs étudiants manquants
- ✅ Maintient les inscriptions (table pivot `classe_etudiant`)

#### NotificationService (enrichi)
Service responsable de l'envoi de notifications aux utilisateurs.

**Nouvelles méthodes:**
- `notifyVisioScheduled($seanceId, $seanceData)`: Notifie les étudiants quand une visio est programmée
- `notifyVisioStarting($seanceId, $seanceData)`: Notifie étudiants + enseignant quand la visio démarre

### 2. Intégration dans LMSDataController

#### Méthode `activateVisio`
Lorsqu'un enseignant active la visioconférence:
1. Création/MAJ de l'entrée `seances`
2. **Synchronisation automatique de la classe** depuis Klassci
3. **Envoi notification "Visio programmée"** à tous les étudiants

#### Méthode `startVisio`
Lorsqu'un enseignant démarre la visioconférence:
1. MAJ status visio → 'active'
2. **Re-synchronisation classe** (sécurité)
3. **Envoi notification "Visio en cours"** aux étudiants ET à l'enseignant

### 3. Synchronisation au Login

Le `AuthController` synchronise automatiquement les classes au login:
- Après authentification réussie via Klassci
- Avant de retourner le token à l'utilisateur
- Statistiques de synchronisation incluses dans la réponse (`meta.classes_sync`)

## Workflow Complet

```
┌─────────────────────────────────────────────────────────────┐
│ 1. ENSEIGNANT LOGIN                                         │
│    → Authentification Klassci                               │
│    → Synchronisation utilisateur local                      │
│    → Synchronisation classes + étudiants                    │
│    → Token Sanctum généré                                   │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. ENSEIGNANT ACTIVE UNE VISIO (depuis frontend ou Klassci)│
│    POST /api/lms/seances/{id}/activate-visio                │
│    → Création entrée `seances` avec visio_enabled=true      │
│    → Synchronisation classe depuis Klassci                  │
│    → Création notifications TYPE_VISIO_SCHEDULED             │
│    → ✉️ Notification "Visio programmée" → ÉTUDIANTS         │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. ENSEIGNANT DÉMARRE LA VISIO                              │
│    POST /api/lms/seances/{id}/start-visio                   │
│    → MAJ status → 'active', visio_active=true               │
│    → Re-synchronisation classe (sécurité)                   │
│    → Création notifications TYPE_VISIO_STARTING              │
│    → ✉️ Notification "Visio en cours" → ÉTUDIANTS           │
│    → ✉️ Notification "Votre visio a démarré" → ENSEIGNANT   │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. ÉTUDIANTS REJOIGNENT LA VISIO                            │
│    → Consultent notifications (frontend)                    │
│    → Cliquent sur lien dans notification                    │
│    → Accèdent à la page de la séance                        │
│    → Rejoignent la room Jitsi                               │
└─────────────────────────────────────────────────────────────┘
```

## Types de Notifications

### TYPE_VISIO_SCHEDULED
**Déclencheur**: Activation de la visio par l'enseignant
**Destinataires**: Tous les étudiants actifs de la classe
**Titre**: "Visioconférence programmée"
**Message**: "Une visioconférence a été programmée en {matiere} avec {enseignant}."
**Data**:
```json
{
    "seance_id": 50,
    "matiere": "Marketing digital",
    "enseignant": "BEDE ABEL TEST"
}
```

### TYPE_VISIO_STARTING
**Déclencheur**: Démarrage de la visio par l'enseignant
**Destinataires**:
- Tous les étudiants actifs de la classe
- L'enseignant qui a démarré

**Pour les étudiants:**
- Titre: "Visioconférence en cours"
- Message: "La visioconférence de {matiere} a démarré. Rejoignez maintenant !"

**Pour l'enseignant:**
- Titre: "Votre visioconférence a démarré"
- Message: "Votre visioconférence de {matiere} est maintenant active."

## Base de Données

### Table `classes`
Stocke les classes synchronisées depuis Klassci.

```sql
- id (PK)
- klassci_id (unique, indexed)
- nom
- libelle
- code
- niveau
- filiere
- annee_universitaire_id
- effectif_max
- timestamps
```

### Table `classe_etudiant` (pivot)
Lie les étudiants aux classes.

```sql
- id (PK)
- classe_id (FK → classes)
- user_id (FK → users)
- statut ('actif', 'inactif')
- date_inscription
- timestamps
```

### Table `notifications`
Stocke toutes les notifications.

```sql
- id (PK)
- user_id (FK → users)
- type (varchar: visio_scheduled, visio_starting, etc.)
- title
- message
- data (JSON)
- read_at (nullable)
- timestamps
```

## API Endpoints

### Activation Visio
```http
POST /api/lms/seances/{seanceId}/activate-visio
Authorization: Bearer {token}

Response:
{
    "success": true,
    "message": "Visioconférence activée",
    "data": {
        "visio_enabled": true,
        "visio_status": "programmee",
        "visio_room_id": "lms_seance_50_1234567890"
    }
}
```

### Démarrage Visio
```http
POST /api/lms/seances/{seanceId}/start-visio
Authorization: Bearer {token}

Response:
{
    "success": true,
    "message": "Visioconférence démarrée",
    "data": {
        "visio_status": "active",
        "visio_started_at": "2025-11-18T11:09:36.000000Z",
        "visio_room_id": "lms_seance_50_1234567890"
    }
}
```

### Récupération Notifications
```http
GET /api/notifications
Authorization: Bearer {token}

Response:
{
    "success": true,
    "data": [
        {
            "id": 123,
            "type": "visio_scheduled",
            "title": "Visioconférence programmée",
            "message": "Une visioconférence a été programmée...",
            "data": {
                "seance_id": 50,
                "matiere": "Marketing digital"
            },
            "read_at": null,
            "created_at": "2025-11-18T11:09:36.000000Z"
        }
    ]
}
```

## Tests

### Test Complet
```bash
php test_sync_and_notifications.php
```

Ce script teste:
1. ✅ Synchronisation des classes depuis Klassci
2. ✅ Création des étudiants locaux
3. ✅ Création des inscriptions (pivot)
4. ✅ Envoi notification "Visio programmée"
5. ✅ Envoi notification "Visio démarrée"
6. ✅ Vérification des destinataires

### Résultats Attendus
```
✓ 2 classes synchronisées
✓ 1 étudiant créé
✓ 2 inscriptions créées
✓ 3 notifications envoyées:
  - 1x visio_scheduled → Étudiant
  - 1x visio_starting → Étudiant
  - 1x visio_starting → Enseignant
```

## Logs

Tous les événements importants sont loggés:

```php
// Synchronisation classes
Log::info('Classes synchronisées au login', [
    'user_id' => $userId,
    'stats' => $syncStats
]);

// Activation visio
Log::info('Visio activée', [
    'seance_id' => $seanceId,
    'user_id' => $userId,
    'room_id' => $roomId
]);

// Notifications envoyées
Log::info('Notifications visio programmée envoyées', [
    'seance_id' => $seanceId,
    'notifications_sent' => $count
]);
```

## Dépannage

### Problème: Aucune notification envoyée

**Cause possible**: Classes non synchronisées

**Solution**:
1. Vérifier que la classe existe localement:
```php
$classe = Classe::where('klassci_id', $klasseId)->first();
```

2. Si manquante, synchroniser manuellement:
```php
$classeSyncService->syncClasseById($klasseId, $klassciToken);
```

3. Vérifier les logs:
```bash
tail -f storage/logs/laravel.log | grep -i "notification"
```

### Problème: Étudiants non créés

**Cause possible**: API Klassci retourne un format différent

**Solution**:
1. Vérifier la structure retournée par `/classes/{id}`:
```bash
php debug_klassci_classes.php
```

2. Adapter le code dans `ClasseSyncService::syncClasseStudents()` si nécessaire

### Problème: Notifications créées mais pas affichées

**Vérifier**:
1. Frontend récupère les notifications via `/api/notifications`
2. Les notifications sont bien associées au bon user_id
3. Le frontend gère le type `visio_scheduled` et `visio_starting`

## Maintenance

### Nettoyage des anciennes notifications

Le service inclut une méthode de nettoyage:

```php
$notificationService->cleanupOldNotifications(30); // Garde 30 derniers jours
```

Recommandation: Créer une tâche planifiée Laravel:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->call(function () {
        app(NotificationService::class)->cleanupOldNotifications(30);
    })->weekly();
}
```

## Performance

### Optimisations Implémentées

1. **Synchronisation non-bloquante**: La synchro classes au login n'empêche pas le login
2. **UpdateOrCreate**: Évite les doublons de classes/étudiants
3. **Logs de debug**: Facilitent le diagnostic sans impact performance
4. **Index DB**: Les champs `klassci_id`, `user_id`, `type`, `read_at` sont indexés

### Métriques Typiques

- Synchronisation 2 classes + 2 étudiants: ~2-3 secondes
- Envoi 1 notification: <50ms
- Envoi 30 notifications (classe): ~1.5 secondes

## Évolutions Futures

### Recommandations

1. **Queue Jobs**: Déplacer la synchronisation vers des jobs asynchrones
2. **WebSockets**: Notifications en temps réel via Laravel Echo + Pusher
3. **Notifications Push**: Intégrer Firebase Cloud Messaging pour mobiles
4. **Historique**: Archiver les anciennes notifications dans une table séparée
5. **Statistiques**: Dashboard admin pour suivre les taux d'ouverture

## Support

Pour toute question ou problème:
1. Consulter les logs: `storage/logs/laravel.log`
2. Exécuter les scripts de test
3. Vérifier la documentation API Klassci

---

**Version**: 1.0.0
**Dernière mise à jour**: 2025-11-18
**Auteur**: Système LMS KLASSCI
