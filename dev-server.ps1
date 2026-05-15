# Local Development Server Startup Script (Windows)
# Runs PHP built-in server on localhost:8000
#
# Usage:
#   .\dev-server.ps1
#
# Then access the app at: http://localhost:8000/
# API endpoints at: http://localhost:8000/api/auth/login (for example)

$scriptPath = Split-Path -Parent -Path $MyInvocation.MyCommand.Definition
Set-Location $scriptPath

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "Water Treatment Service App - Dev Server" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Before starting, ensure:" -ForegroundColor Yellow
Write-Host "  1. Local MySQL is running"
Write-Host "  2. Database created: CREATE DATABASE service_app_dev;"
Write-Host "  3. Schema imported: mysql -u root service_app_dev < database/migrations/001_initial_schema.sql"
Write-Host ""

Write-Host "Starting PHP built-in server on http://localhost:8000" -ForegroundColor Green
Write-Host "Press Ctrl+C to stop." -ForegroundColor Yellow
Write-Host ""

& php -S localhost:8000 -t public/
