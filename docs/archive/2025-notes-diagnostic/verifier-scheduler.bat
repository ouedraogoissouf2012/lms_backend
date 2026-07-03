@echo off
echo ═══════════════════════════════════════════════
echo    VÉRIFICATION DU SCHEDULER LARAVEL
echo ═══════════════════════════════════════════════
echo.

cd /d "%~dp0"

echo [1/3] Vérification de la tâche planifiée...
schtasks /Query /TN "Laravel_LMS_Scheduler" /FO LIST | findstr "État"

if %ERRORLEVEL% EQU 0 (
    echo ✅ La tâche existe
) else (
    echo ❌ La tâche n'existe pas
    echo.
    echo Vous devez exécuter "install-scheduler.bat" en tant qu'administrateur.
    pause
    exit
)

echo.
echo [2/3] Vérification des logs...
if exist "storage\logs\scheduler.log" (
    echo ✅ Fichier de logs trouvé
    echo.
    echo Dernières lignes du log :
    echo ─────────────────────────────────────────────
    powershell -Command "Get-Content storage\logs\scheduler.log -Tail 5"
    echo ─────────────────────────────────────────────
) else (
    echo ⚠️  Aucun log trouvé (attendre 1-2 minutes)
)

echo.
echo [3/3] Liste des tâches planifiées Laravel...
php artisan schedule:list

echo.
echo ═══════════════════════════════════════════════
echo    RÉSULTAT
echo ═══════════════════════════════════════════════
echo.
echo Si vous voyez des logs ci-dessus : ✅ Tout fonctionne
echo Si aucun log : ⏳ Attendre 1-2 minutes
echo.

pause
