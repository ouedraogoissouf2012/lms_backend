# Script pour ajouter les méthodes d'export au controller

$controllerPath = "app\Http\Controllers\API\LMSDataController.php"
$methodsPath = "export_methods_to_add.php"

# Lire les fichiers
$controllerContent = Get-Content $controllerPath -Raw
$newMethods = Get-Content $methodsPath -Raw

# Trouver la dernière accolade
$lastBraceIndex = $controllerContent.LastIndexOf('}')

# Séparer avant et après
$beforeBrace = $controllerContent.Substring(0, $lastBraceIndex)

# Reconstruire avec les nouvelles méthodes
$updatedContent = $beforeBrace + "`n" + $newMethods + "`n}"

# Sauvegarder
Set-Content $controllerPath -Value $updatedContent -NoNewline

Write-Host "✅ Méthodes ajoutées avec succès!"
