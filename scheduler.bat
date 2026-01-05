@echo off
REM Script pour exécuter le scheduler Laravel
REM Ce script doit être exécuté chaque minute par le Planificateur de tâches Windows

cd /d "C:\Users\USER PC\Documents\propre à moi\lms-backend"
php artisan schedule:run >> storage\logs\scheduler.log 2>&1
