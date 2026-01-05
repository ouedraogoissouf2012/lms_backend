# Installation du Scheduler Laravel (Automatique)

## Problème résolu

Les jobs automatiques (fermeture des séances, détection déconnexions, etc.) ne s'exécutaient pas car le scheduler Laravel n'était pas configuré pour tourner automatiquement.

## Solution : Tâche planifiée Windows

Le scheduler sera exécuté **automatiquement chaque minute** par Windows.

---

## Installation (1 seule fois)

### Étape 1 : Ouvrir PowerShell en Administrateur

1. Appuyer sur `Windows + X`
2. Choisir **"Windows PowerShell (Admin)"** ou **"Terminal (Admin)"**

### Étape 2 : Exécuter le script d'installation

Copier-coller cette commande :

```powershell
cd "C:\Users\USER PC\Documents\propre à moi\lms-backend"
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass
.\setup-scheduler-windows.ps1
```

### Étape 3 : Vérifier que ça fonctionne

Attendre 2-3 minutes, puis :

```bash
php artisan queue:failed
type storage\logs\scheduler.log
```

Vous devriez voir des logs d'exécution.

---

## Vérification manuelle

### Voir les logs du scheduler

```bash
type storage\logs\scheduler.log
```

### Tester manuellement

```bash
php artisan schedule:run
```

### Voir les tâches planifiées

```bash
php artisan schedule:list
```

---

## Gérer la tâche Windows

### Ouvrir le Planificateur de tâches

1. Appuyer sur `Windows + R`
2. Taper `taskschd.msc`
3. Chercher `Laravel_LMS_Scheduler`

### Désactiver temporairement

Clic droit sur `Laravel_LMS_Scheduler` → **Désactiver**

### Réactiver

Clic droit sur `Laravel_LMS_Scheduler` → **Activer**

### Supprimer

Clic droit sur `Laravel_LMS_Scheduler` → **Supprimer**

---

## Jobs automatiques configurés

Une fois le scheduler actif, ces jobs s'exécuteront automatiquement :

| Job | Fréquence | Description |
|-----|-----------|-------------|
| **DetectDisconnectedParticipants** | Toutes les 2 min | Détecte les participants sans heartbeat depuis 5+ min |
| **AutoCloseEmptySeances** | Toutes les 5 min | Ferme les séances vides/abandonnées (4 règles) |
| **SyncKlassciSeances** | Toutes les 5 min | Synchronise les séances depuis KLASSCI |
| **FinalizeSeanceAttendances** | Toutes les 10 min | Finalise les présences des séances terminées |
| **CleanObsoleteSeances** | Toutes les 30 min | Nettoie les séances supprimées de KLASSCI |
| **ArchiveOldSeances** | Quotidien (2h) | Archive les vieilles séances |
| **CleanOldEvaluations** | Quotidien (3h) | Nettoie les évaluations périmées |

---

## Résultat attendu

Avec le scheduler actif :

✅ Les séances se ferment automatiquement 5 min après le départ de l'enseignant
✅ Les participants déconnectés sont détectés en temps réel
✅ Les horraires de présence sont corrects
✅ Plus besoin de cliquer sur "Terminer" manuellement

---

## Dépannage

### La tâche ne s'exécute pas

Vérifier :
- Le chemin dans `scheduler.bat` est correct
- PHP est accessible depuis la ligne de commande (`php -v`)
- Les permissions d'exécution

### Erreurs dans les logs

Regarder :
- `storage/logs/scheduler.log` - Logs du scheduler
- `storage/logs/laravel.log` - Logs de l'application
