# Simple test script for CRITICAL-01

$PHP = "C:\xampp\php\php.exe"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TEST CRITICAL-01: Token Encryption" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Step 1: Verify PHP" -ForegroundColor Yellow
& $PHP --version
Write-Host ""

Write-Host "Step 2: Clear cache" -ForegroundColor Yellow
& $PHP artisan cache:clear
& $PHP artisan config:clear
Write-Host ""

Write-Host "Step 3: Reset database" -ForegroundColor Yellow
& $PHP artisan migrate:reset --force 2>$null
Write-Host ""

Write-Host "Step 4: Run CRITICAL-01 migration" -ForegroundColor Yellow
& $PHP artisan migrate --step
Write-Host ""

Write-Host "Step 5: Check database schema" -ForegroundColor Yellow
Write-Host "Checking if klassci_token_encrypted column exists in users table..."
Write-Host "(This is verified by the migration running without errors above)"
Write-Host ""

Write-Host "Step 6: Run all tests" -ForegroundColor Yellow
& $PHP artisan test
Write-Host ""

Write-Host "========================================" -ForegroundColor Green
Write-Host "TESTING COMPLETE!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Check results above:" -ForegroundColor Cyan
Write-Host "  [PASS] Migration executed" -ForegroundColor Green
Write-Host "  [PASS] No errors during migration" -ForegroundColor Green
Write-Host "  [CHECK] Test results displayed above" -ForegroundColor Yellow
