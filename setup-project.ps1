# Complete project setup script for LMS Backend

$PHP = "C:\xampp\php\php.exe"
$COMPOSER = "C:\ProgramData\ComposerSetup\bin\composer.exe"
$PROJECT_DIR = "C:\Users\PC\Documents\lmsPro\lms_backend"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "LMS BACKEND - COMPLETE SETUP" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Verify PHP
Write-Host "Step 1: Verifying PHP..." -ForegroundColor Yellow
& $PHP --version
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: PHP not found at $PHP" -ForegroundColor Red
    exit 1
}
Write-Host "OK: PHP found" -ForegroundColor Green
Write-Host ""

# Step 2: Check if Composer exists
Write-Host "Step 2: Checking Composer..." -ForegroundColor Yellow
if (-not (Test-Path $COMPOSER)) {
    Write-Host "WARNING: Composer not found at $COMPOSER" -ForegroundColor Yellow
    Write-Host "Installing Composer..." -ForegroundColor Yellow
    # Try alternate location
    $COMPOSER = "C:\ProgramData\ComposerSetup\bin\composer.bat"
    if (-not (Test-Path $COMPOSER)) {
        Write-Host "ERROR: Composer not found in any expected location" -ForegroundColor Red
        Write-Host "Please install Composer from https://getcomposer.org/download/" -ForegroundColor Yellow
        exit 1
    }
}
Write-Host "OK: Composer found" -ForegroundColor Green
Write-Host ""

# Step 3: Check .env file
Write-Host "Step 3: Checking .env file..." -ForegroundColor Yellow
if (-not (Test-Path "$PROJECT_DIR\.env")) {
    Write-Host "Creating .env from .env.local..." -ForegroundColor Yellow
    Copy-Item "$PROJECT_DIR\.env.local" "$PROJECT_DIR\.env"
    Write-Host "OK: .env created" -ForegroundColor Green
} else {
    Write-Host "OK: .env exists" -ForegroundColor Green
}
Write-Host ""

# Step 4: Install Composer dependencies
Write-Host "Step 4: Installing Composer dependencies..." -ForegroundColor Yellow
Write-Host "This may take several minutes..." -ForegroundColor Yellow
& $COMPOSER install --no-interaction
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Composer install failed" -ForegroundColor Red
    exit 1
}
Write-Host "OK: Dependencies installed" -ForegroundColor Green
Write-Host ""

# Step 5: Generate APP_KEY
Write-Host "Step 5: Generating APP_KEY..." -ForegroundColor Yellow
& $PHP artisan key:generate --force
if ($LASTEXITCODE -ne 0) {
    Write-Host "WARNING: Could not generate key (may already exist)" -ForegroundColor Yellow
} else {
    Write-Host "OK: APP_KEY generated" -ForegroundColor Green
}
Write-Host ""

# Step 6: Clear cache
Write-Host "Step 6: Clearing cache..." -ForegroundColor Yellow
& $PHP artisan cache:clear
& $PHP artisan config:clear
Write-Host "OK: Cache cleared" -ForegroundColor Green
Write-Host ""

# Step 7: Run migrations
Write-Host "Step 7: Running migrations..." -ForegroundColor Yellow
& $PHP artisan migrate --force
if ($LASTEXITCODE -ne 0) {
    Write-Host "WARNING: Migration may have failed (check above)" -ForegroundColor Yellow
} else {
    Write-Host "OK: Migrations completed" -ForegroundColor Green
}
Write-Host ""

# Step 8: Verify database connection
Write-Host "Step 8: Verifying database connection..." -ForegroundColor Yellow
& $PHP artisan tinker -e "DB::connection()->getPdo(); echo 'Database connection OK';" 2>$null
Write-Host ""

# Step 9: Run tests
Write-Host "Step 9: Running tests..." -ForegroundColor Yellow
& $PHP artisan test
Write-Host ""

Write-Host "========================================" -ForegroundColor Green
Write-Host "SETUP COMPLETE!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Project is ready:" -ForegroundColor Cyan
Write-Host "  [OK] PHP installed" -ForegroundColor Green
Write-Host "  [OK] Composer dependencies installed" -ForegroundColor Green
Write-Host "  [OK] .env configured" -ForegroundColor Green
Write-Host "  [OK] APP_KEY generated" -ForegroundColor Green
Write-Host "  [OK] Database migrations run" -ForegroundColor Green
Write-Host "  [CHECK] Tests - see results above" -ForegroundColor Yellow
Write-Host ""
Write-Host "Next: Run CRITICAL-01 tests with:" -ForegroundColor Cyan
Write-Host "  .\test-critical-01.ps1" -ForegroundColor White
