# Script PowerShell pour configurer le scheduler Laravel sur Windows
# Exécuter en tant qu'administrateur

Write-Host "=== Configuration du Scheduler Laravel pour Windows ===" -ForegroundColor Cyan
Write-Host ""

$taskName = "Laravel_LMS_Scheduler"
$scriptPath = "C:\Users\USER PC\Documents\propre à moi\lms-backend\scheduler.bat"

# Vérifier si la tâche existe déjà
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

if ($existingTask) {
    Write-Host "La tâche '$taskName' existe déjà. Suppression..." -ForegroundColor Yellow
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

# Créer l'action (exécuter le fichier batch)
$action = New-ScheduledTaskAction -Execute "cmd.exe" -Argument "/c `"$scriptPath`""

# Créer le déclencheur (chaque minute, pour une durée de 10 ans)
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)

# Créer les paramètres de la tâche
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable:$false `
    -DontStopOnIdleEnd `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

# Créer le principal (utilisateur actuel)
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Highest

# Enregistrer la tâche
Write-Host "Création de la tâche planifiée..." -ForegroundColor Green
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description "Exécute le scheduler Laravel LMS toutes les minutes"

Write-Host ""
Write-Host "✅ Tâche planifiée créée avec succès !" -ForegroundColor Green
Write-Host ""
Write-Host "La tâche '$taskName' va maintenant exécuter le scheduler Laravel chaque minute." -ForegroundColor Cyan
Write-Host ""
Write-Host "Pour vérifier que ça fonctionne :" -ForegroundColor Yellow
Write-Host "1. Ouvrir le Planificateur de tâches Windows" -ForegroundColor White
Write-Host "2. Chercher '$taskName'" -ForegroundColor White
Write-Host "3. Vérifier l'historique d'exécution" -ForegroundColor White
Write-Host ""
Write-Host "Logs disponibles dans : storage\logs\scheduler.log" -ForegroundColor Yellow
Write-Host ""

# Démarrer la tâche immédiatement pour tester
Write-Host "Démarrage immédiat de la tâche pour test..." -ForegroundColor Green
Start-ScheduledTask -TaskName $taskName

Write-Host ""
Write-Host "✅ Configuration terminée !" -ForegroundColor Green
