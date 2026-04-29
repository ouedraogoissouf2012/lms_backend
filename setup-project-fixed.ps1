# Complete project setup with PATH fix

$PHP_PATH = "C:\xampp\php"
$COMPOSER = "C:\ProgramData\ComposerSetup\bin\composer.bat"
$PROJECT_DIR = "C:\Users\PC\Documents\lmsPro\lms_backend"

# Add PHP to PATH temporarily
$env:PATH = "$PHP_PATH;$env:PATH"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "LMS BACKEND - COMPLETE SETUP" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Verify PHP
Write-Host "Step 1: Verifying PHP..." -ForegroundColor Yellow
php --version
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: PHP not working" -ForegroundColor Red
    exit 1
}
Write-Host "OK: PHP found" -ForegroundColor Green
Write-Host ""

# Step 2: Check Composer
Write-Host "Step 2: Checking Composer..." -ForegroundColor Yellow
& $COMPOSER --version
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Composer not found" -ForegroundColor Red
    exit 1
}
Write-Host "OK: Composer found" -ForegroundColor Green
Write-Host ""

# Step 3: Check .env file
Write-Host "Step 3: Checking .env file..." -ForegroundColor Yellow
if (-not (Test-Path "$PROJECT_DIR\.env")) {
    Write-Host "Creating .env from .env.local..." -ForegroundColor Yellow
    Copy-Item "$PROJECT_DIR\.env.local" "$PROJECT_DIR\.env" -Force
    Write-Host "OK: .env created" -ForegroundColor Green
} else {
    Write-Host "OK: .env already exists" -ForegroundColor Green
}
Write-Host ""

# Step 4: Install Composer dependencies
Write-Host "Step 4: Installing Composer dependencies..." -ForegroundColor Yellow
Write-Host "This may take several minutes..." -ForegroundColor Yellow
& $COMPOSER install --no-interaction --working-dir=$PROJECT_DIR
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Composer install failed" -ForegroundColor Red
    exit 1
}
Write-Host "OK: Dependencies installed" -ForegroundColor Green
Write-Host ""

# Step 5: Generate APP_KEY
Write-Host "Step 5: Generating APP_KEY..." -ForegroundColor Yellow
php "$PROJECT_DIR\artisan" key:generate --force
Write-Host "OK: APP_KEY generated" -ForegroundColor Green
Write-Host ""

# Step 6: Clear cache
Write-Host "Step 6: Clearing cache..." -ForegroundColor Yellow
php "$PROJECT_DIR\artisan" cache:clear
php "$PROJECT_DIR\artisan" config:clear
Write-Host "OK: Cache cleared" -ForegroundColor Green
Write-Host ""

# Step 7: Run migrations
Write-Host "Step 7: Running migrations..." -ForegroundColor Yellow
php "$PROJECT_DIR\artisan" migrate --force
Write-Host ""

# Step 8: Run tests
Write-Host "Step 8: Running tests..." -ForegroundColor Yellow
php "$PROJECT_DIR\artisan" test
Write-Host ""

Write-Host "========================================" -ForegroundColor Green
Write-Host "SETUP COMPLETE!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Project is ready to test CRITICAL-01!" -ForegroundColor Cyan
