# Database Setup Helper (Windows)
# Creates local development database and imports schema
#
# Usage:
#   .\setup-local-db.ps1 -MySqlUser root -MySqlPassword "" -DbName service_app_dev
#
# Prerequisites:
#   - MySQL installed and in PATH (or full path specified)
#   - MySQL server running

param(
    [string]$MySqlUser = "root",
    [string]$MySqlPassword = "",
    [string]$DbName = "service_app_dev",
    [string]$MySqlPath = "mysql",
    [string]$AdminEmail = "admin@example.com",
    [string]$AdminPassword = "password123",
    [string]$AdminName = "Admin User",
    [string]$AdminRole = "admin",
    [switch]$SeedDemoData
)

$scriptPath = Split-Path -Parent -Path $MyInvocation.MyCommand.Definition
Set-Location $scriptPath

function Escape-SqlValue {
    param(
        [AllowNull()]
        [string]$Value
    )

    if ($null -eq $Value) {
        return ""
    }

    return $Value.Replace("'", "''")
}

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "Database Setup Helper" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "MySQL Configuration:" -ForegroundColor Yellow
Write-Host "  User: $MySqlUser"
Write-Host "  Database: $DbName"
Write-Host "  Admin Email: $AdminEmail"
Write-Host ""

# Test MySQL connection
Write-Host "Testing MySQL connection..." -ForegroundColor Green
$testCmd = if ($MySqlPassword) {
    "mysql -u $MySqlUser -p$MySqlPassword -e 'SELECT 1;' 2>&1"
} else {
    "mysql -u $MySqlUser -e 'SELECT 1;' 2>&1"
}

$testResult = Invoke-Expression $testCmd
if ($testResult -like "*ERROR*" -or $LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Cannot connect to MySQL!" -ForegroundColor Red
    Write-Host "Ensure MySQL is running and credentials are correct." -ForegroundColor Red
    Exit 1
}

Write-Host "✓ MySQL connection successful" -ForegroundColor Green
Write-Host ""

# Create database
Write-Host "Creating database '$DbName'..." -ForegroundColor Green
$createDbCmd = if ($MySqlPassword) {
    "mysql -u $MySqlUser -p$MySqlPassword -e 'DROP DATABASE IF EXISTS $DbName; CREATE DATABASE $DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' 2>&1"
} else {
    "mysql -u $MySqlUser -e 'DROP DATABASE IF EXISTS $DbName; CREATE DATABASE $DbName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' 2>&1"
}

$createResult = Invoke-Expression $createDbCmd
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR creating database:" -ForegroundColor Red
    Write-Host $createResult -ForegroundColor Red
    Exit 1
}

Write-Host "✓ Database created" -ForegroundColor Green
Write-Host ""

# Import migrations
$migrationFiles = Get-ChildItem -Path (Join-Path $scriptPath "database\migrations") -Filter "*.sql" | Sort-Object Name
if (-not $migrationFiles) {
    Write-Host "ERROR: No migration files found in database\migrations" -ForegroundColor Red
    Exit 1
}

Write-Host "Importing migrations..." -ForegroundColor Green
foreach ($migration in $migrationFiles) {
    Write-Host "  -> $($migration.Name)" -ForegroundColor Yellow
    $importCmd = if ($MySqlPassword) {
        "Get-Content `"$($migration.FullName)`" | mysql -u $MySqlUser -p$MySqlPassword $DbName 2>&1"
    } else {
        "Get-Content `"$($migration.FullName)`" | mysql -u $MySqlUser $DbName 2>&1"
    }

    $importResult = Invoke-Expression $importCmd
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR importing migration $($migration.Name):" -ForegroundColor Red
        Write-Host $importResult -ForegroundColor Red
        Exit 1
    }
}

Write-Host "✓ Migrations imported successfully" -ForegroundColor Green
Write-Host ""

# Seed default admin user for local API testing
Write-Host "Seeding default admin user..." -ForegroundColor Green
$env:SERVICE_APP_ADMIN_PASSWORD = $AdminPassword
$adminHash = & php -r "echo password_hash(getenv('SERVICE_APP_ADMIN_PASSWORD'), PASSWORD_BCRYPT, ['cost' => 12]);"
Remove-Item Env:SERVICE_APP_ADMIN_PASSWORD -ErrorAction SilentlyContinue

$escapedAdminEmail = Escape-SqlValue $AdminEmail
$escapedAdminName = Escape-SqlValue $AdminName
$escapedAdminRole = Escape-SqlValue $AdminRole

$seedSql = @"
INSERT INTO users (email, password_hash, name, role, is_active)
VALUES ('$escapedAdminEmail', '$adminHash', '$escapedAdminName', '$escapedAdminRole', 1)
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    name = VALUES(name),
    role = VALUES(role),
    is_active = VALUES(is_active);
"@

$seedCmd = if ($MySqlPassword) {
    "mysql -u $MySqlUser -p$MySqlPassword $DbName -e `"$seedSql`" 2>&1"
} else {
    "mysql -u $MySqlUser $DbName -e `"$seedSql`" 2>&1"
}

$seedResult = Invoke-Expression $seedCmd
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR seeding admin user:" -ForegroundColor Red
    Write-Host $seedResult -ForegroundColor Red
    Exit 1
}

Write-Host "✓ Default admin seeded ($AdminEmail / [configured password])" -ForegroundColor Green
Write-Host ""

if ($SeedDemoData) {
    Write-Host "Seeding demo customer/site/equipment..." -ForegroundColor Green

    $demoSql = @"
INSERT INTO customers (name, contact_email, contact_phone, city, province, postal_code, country)
VALUES ('Acme Water Company', 'john@acme.com', '416-555-0100', 'Toronto', 'ON', 'M5V3A8', 'CA');

SET @customer_id = LAST_INSERT_ID();

INSERT INTO sites (customer_id, site_name, address_line1, city, province, postal_code, contact_person, contact_phone)
VALUES (@customer_id, 'Main Office', '123 Main St', 'Toronto', 'ON', 'M5V3A8', 'John Smith', '416-555-0100');

SET @site_id = LAST_INSERT_ID();

INSERT INTO equipment (site_id, equipment_type, model, capacity_liters)
VALUES (@site_id, 'tank', 'Softener Tank', 1000);
"@

    $demoCmd = if ($MySqlPassword) {
        "mysql -u $MySqlUser -p$MySqlPassword $DbName -e `"$demoSql`" 2>&1"
    } else {
        "mysql -u $MySqlUser $DbName -e `"$demoSql`" 2>&1"
    }

    $demoResult = Invoke-Expression $demoCmd
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR seeding demo data:" -ForegroundColor Red
        Write-Host $demoResult -ForegroundColor Red
        Exit 1
    }

    Write-Host "✓ Demo customer/site/equipment seeded" -ForegroundColor Green
    Write-Host ""
}

# Verify tables
Write-Host "Verifying tables..." -ForegroundColor Green
$verifyCmd = if ($MySqlPassword) {
    "mysql -u $MySqlUser -p$MySqlPassword $DbName -e 'SHOW TABLES;' 2>&1"
} else {
    "mysql -u $MySqlUser $DbName -e 'SHOW TABLES;' 2>&1"
}

$tables = Invoke-Expression $verifyCmd
Write-Host $tables
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "✓ Local database ready!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "  1. Copy config\app.local.example.php to config\app.local.php and update local secrets if needed"
Write-Host "  2. Run: .\dev-server.ps1"
Write-Host "  3. Visit: http://localhost:8000"
Write-Host "  4. Fetch CSRF token: GET http://localhost:8000/api/auth/csrf"
Write-Host "  5. Log in with $AdminEmail and the configured admin password"
Write-Host ""
