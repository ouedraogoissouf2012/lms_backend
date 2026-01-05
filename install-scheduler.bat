@echo off
echo ===================================================
echo Installation du Scheduler Laravel (Automatique)
echo ===================================================
echo.

cd /d "%~dp0"

echo Création de la tâche planifiée Windows...
schtasks /Create /TN "Laravel_LMS_Scheduler" /XML "laravel-scheduler-task.xml" /F

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ✅ Tâche planifiée créée avec succès !
    echo.
    echo La tâche "Laravel_LMS_Scheduler" va maintenant s'exécuter chaque minute.
    echo.
    echo Pour vérifier :
    echo 1. Ouvrir le Planificateur de tâches Windows (taskschd.msc)
    echo 2. Chercher "Laravel_LMS_Scheduler"
    echo.
    echo Logs disponibles dans : storage\logs\scheduler.log
    echo.

    echo Démarrage immédiat de la tâche pour test...
    schtasks /Run /TN "Laravel_LMS_Scheduler"

    echo.
    echo ✅ Installation terminée !
    echo.
    echo Attendez 1-2 minutes, puis vérifiez les logs :
    echo   type storage\logs\scheduler.log
    echo.
) else (
    echo.
    echo ❌ Erreur lors de la création de la tâche.
    echo.
    echo Vous devez exécuter ce script en tant qu'ADMINISTRATEUR.
    echo.
    echo Clic droit sur "install-scheduler.bat" → "Exécuter en tant qu'administrateur"
    echo.
)

pause
