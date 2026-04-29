# Enable PostgreSQL extension in PHP

$PHP_INI = "C:\xampp\php\php.ini"
$PHP_EXT_DIR = "C:\xampp\php\ext"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Enable PostgreSQL Extension for PHP" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check if php.ini exists
if (-not (Test-Path $PHP_INI)) {
    Write-Host "ERROR: php.ini not found at $PHP_INI" -ForegroundColor Red
    exit 1
}
Write-Host "OK: php.ini found" -ForegroundColor Green
Write-Host ""

# Check if PostgreSQL extension exists
if (-not (Test-Path "$PHP_EXT_DIR\php_pdo_pgsql.dll")) {
    Write-Host "ERROR: php_pdo_pgsql.dll not found in $PHP_EXT_DIR" -ForegroundColor Red
    Write-Host "PostgreSQL extension not installed in this XAMPP" -ForegroundColor Yellow
    Write-Host "Trying alternate location..." -ForegroundColor Yellow

    # List available extensions
    Write-Host "Available extensions:" -ForegroundColor Yellow
    Get-ChildItem "$PHP_EXT_DIR\php_*.dll" | ForEach-Object { Write-Host "  - $($_.Name)" }

    exit 1
}
Write-Host "OK: php_pdo_pgsql.dll found" -ForegroundColor Green
Write-Host ""

# Read php.ini
$phpIniContent = Get-Content $PHP_INI -Raw

# Check if already enabled
if ($phpIniContent -match '^\s*extension\s*=\s*pdo_pgsql' -or $phpIniContent -match '^\s*extension\s*=\s*php_pdo_pgsql') {
    Write-Host "OK: PostgreSQL extension already enabled" -ForegroundColor Green
    exit 0
}

# Enable the extension
Write-Host "Enabling PostgreSQL extension..." -ForegroundColor Yellow

# Find the line with ;extension= and add our extension after it
if ($phpIniContent -match ';extension=') {
    # Add the extension after the first ;extension= line
    $phpIniContent = $phpIniContent -replace '(;extension=)', "extension=pdo_pgsql`r`n`$1"

    Set-Content $PHP_INI -Value $phpIniContent -Force
    Write-Host "OK: PostgreSQL extension enabled in php.ini" -ForegroundColor Green
} else {
    Write-Host "ERROR: Could not find extension section in php.ini" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Extension enabled successfully!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "1. Restart XAMPP" -ForegroundColor White
Write-Host "2. Run: .\setup-project-fixed.ps1" -ForegroundColor White
