@echo off
REM Script pour exécuter le scheduler Laravel (DEV WINDOWS UNIQUEMENT)
REM Ce script doit être exécuté chaque minute par le Planificateur de tâches Windows
REM La racine du projet est déduite de l'emplacement du script (scripts\dev-windows\..\..)

cd /d "%~dp0..\.."
php artisan schedule:run >> storage\logs\scheduler.log 2>&1
