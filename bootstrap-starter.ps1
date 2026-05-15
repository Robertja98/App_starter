param(
    [Parameter(Mandatory = $true)]
    [string]$AppName,

    [Parameter(Mandatory = $true)]
    [string]$AppSlug,

    [string]$DatabaseName,
    [string]$AdminEmail = 'admin@example.com',
    [string]$AdminPassword = 'password123',
    [switch]$Preview
)

$scriptPath = Split-Path -Parent -Path $MyInvocation.MyCommand.Definition
Set-Location $scriptPath

function Convert-ToTitleCaseSlug {
    param([string]$Value)

    $parts = (($Value -replace '[-_]+', ' ').Trim() -split '\s+' | Where-Object { $_ }) | ForEach-Object {
        if ($_.Length -eq 1) {
            $_.ToUpperInvariant()
        } else {
            $_.Substring(0, 1).ToUpperInvariant() + $_.Substring(1)
        }
    }

    return ($parts -join ' ')
}

function Replace-InFile {
    param(
        [string]$Path,
        [string]$OldValue,
        [string]$NewValue
    )

    if (-not (Test-Path $Path)) {
        return $false
    }

    $content = Get-Content $Path -Raw
    if ($content -notlike "*${OldValue}*") {
        return $false
    }

    if ($Preview) {
        Write-Host "[PREVIEW] $Path" -ForegroundColor Yellow
        Write-Host "          $OldValue -> $NewValue" -ForegroundColor DarkYellow
        return $true
    }

    $updated = $content.Replace($OldValue, $NewValue)
    if ($updated -ne $content) {
        [System.IO.File]::WriteAllText($Path, $updated, [System.Text.UTF8Encoding]::new($false))
        Write-Host "Updated $Path" -ForegroundColor Green
        return $true
    }

    return $false
}

if (-not $DatabaseName) {
    $DatabaseName = "${AppSlug}_dev"
}

$sessionName = "${AppSlug}_session"
$apiKeyPrefix = "${AppSlug}_"
$workspaceTitle = Convert-ToTitleCaseSlug -Value $AppSlug
$workspaceOld = Join-Path $scriptPath 'Service App.code-workspace'
$workspaceNew = Join-Path $scriptPath ($workspaceTitle + '.code-workspace')

$replacements = @(
    @{ Path = Join-Path $scriptPath 'README.md'; Old = '# PHP MySQL PWA Starter'; New = '# ' + $AppName },
    @{ Path = Join-Path $scriptPath 'WORKLOG.md'; Old = '# WORKLOG – PHP MySQL PWA Starter'; New = '# WORKLOG – ' + $AppName },
    @{ Path = Join-Path $scriptPath 'config\app.local.example.php'; Old = "'name' => 'My App Starter'"; New = "'name' => '" + $AppName + "'" },
    @{ Path = Join-Path $scriptPath 'config\app.local.example.php'; Old = "'database' => 'app_local_dev'"; New = "'database' => '" + $DatabaseName + "'" },
    @{ Path = Join-Path $scriptPath 'config\app.php'; Old = "'database' => getenv('DB_NAME') ?: 'service_app'"; New = "'database' => getenv('DB_NAME') ?: '" + $DatabaseName + "'" },
    @{ Path = Join-Path $scriptPath 'config\app.php'; Old = "'name'      => getenv('APP_NAME') ?: 'Water Treatment Service App'"; New = "'name'      => getenv('APP_NAME') ?: '" + $AppName + "'" },
    @{ Path = Join-Path $scriptPath 'config\app.php'; Old = "'short_name' => getenv('APP_SHORT_NAME') ?: 'Service App'"; New = "'short_name' => getenv('APP_SHORT_NAME') ?: '" + $workspaceTitle + "'" },
    @{ Path = Join-Path $scriptPath 'config\app.php'; Old = "'description' => getenv('APP_DESCRIPTION') ?: 'Field service app for water treatment equipment inspections and service visits'"; New = "'description' => getenv('APP_DESCRIPTION') ?: '" + $AppName + " application'" },
    @{ Path = Join-Path $scriptPath 'config\app.php'; Old = "'name' => getenv('SESSION_NAME') ?: 'service_app_session'"; New = "'name' => getenv('SESSION_NAME') ?: '" + $sessionName + "'" },
    @{ Path = Join-Path $scriptPath 'config\app.php'; Old = "'api_key_prefix' => getenv('API_KEY_PREFIX') ?: 'svcapp_'"; New = "'api_key_prefix' => getenv('API_KEY_PREFIX') ?: '" + $apiKeyPrefix + "'" },
    @{ Path = Join-Path $scriptPath 'setup-local-db.ps1'; Old = '[string]$DbName = "service_app_dev"'; New = '[string]$DbName = "' + $DatabaseName + '"' },
    @{ Path = Join-Path $scriptPath 'setup-local-db.ps1'; Old = '[string]$AdminEmail = "admin@example.com"'; New = '[string]$AdminEmail = "' + $AdminEmail + '"' },
    @{ Path = Join-Path $scriptPath 'setup-local-db.ps1'; Old = '[string]$AdminPassword = "password123"'; New = '[string]$AdminPassword = "' + $AdminPassword + '"' },
    @{ Path = Join-Path $scriptPath 'tests\backend_smoke.php'; Old = "'admin_email' => getenv('SMOKE_ADMIN_EMAIL') ?: 'admin@example.com'"; New = "'admin_email' => getenv('SMOKE_ADMIN_EMAIL') ?: '" + $AdminEmail + "'" },
    @{ Path = Join-Path $scriptPath 'tests\backend_smoke.php'; Old = "'admin_password' => getenv('SMOKE_ADMIN_PASSWORD') ?: 'password123'"; New = "'admin_password' => getenv('SMOKE_ADMIN_PASSWORD') ?: '" + $AdminPassword + "'" },
    @{ Path = Join-Path $scriptPath 'public\app.html'; Old = 'Field service app for water treatment equipment inspections'; New = $AppName + ' web application' },
    @{ Path = Join-Path $scriptPath 'public\app.html'; Old = 'Service App'; New = $workspaceTitle },
    @{ Path = Join-Path $scriptPath 'public\manifest.json'; Old = 'Water Treatment Service App'; New = $AppName },
    @{ Path = Join-Path $scriptPath 'public\manifest.json'; Old = 'Service App'; New = $workspaceTitle },
    @{ Path = Join-Path $scriptPath 'public\manifest.json'; Old = 'Field service app for water treatment equipment inspections and service visits'; New = $AppName + ' application' },
    @{ Path = Join-Path $scriptPath 'dev-server.ps1'; Old = 'Water Treatment Service App - Dev Server'; New = $AppName + ' - Dev Server' },
    @{ Path = Join-Path $scriptPath 'dev-server.ps1'; Old = '  2. Database created: CREATE DATABASE service_app_dev;'; New = '  2. Database created: CREATE DATABASE ' + $DatabaseName + ';' },
    @{ Path = Join-Path $scriptPath 'dev-server.ps1'; Old = '  3. Schema imported: mysql -u root service_app_dev < database/migrations/001_initial_schema.sql'; New = '  3. Schema imported: mysql -u root ' + $DatabaseName + ' < database/migrations/001_initial_schema.sql' },
    @{ Path = Join-Path $scriptPath 'dev-server.sh'; Old = 'Water Treatment Service App - Dev Server'; New = $AppName + ' - Dev Server' },
    @{ Path = Join-Path $scriptPath 'dev-server.sh'; Old = "  2. Database created: mysql -u root -e 'CREATE DATABASE service_app_dev;'"; New = "  2. Database created: mysql -u root -e 'CREATE DATABASE " + $DatabaseName + ";'" },
    @{ Path = Join-Path $scriptPath 'dev-server.sh'; Old = '  3. Schema imported: mysql -u root service_app_dev < database/migrations/001_initial_schema.sql'; New = '  3. Schema imported: mysql -u root ' + $DatabaseName + ' < database/migrations/001_initial_schema.sql' },
    @{ Path = Join-Path $scriptPath 'LOCAL_TESTING_GUIDE.md'; Old = 'service_app_dev'; New = $DatabaseName },
    @{ Path = Join-Path $scriptPath 'LOCAL_TESTING_GUIDE.md'; Old = 'service_app_session'; New = $sessionName },
    @{ Path = Join-Path $scriptPath 'TEMPLATE_GUIDE.md'; Old = '7. Run `./setup-local-db.ps1` with the new admin values if local bootstrap seeding is still desired.'; New = '7. Run `./bootstrap-starter.ps1` once to rename the obvious placeholders, then run `./setup-local-db.ps1` with the new admin values if local bootstrap seeding is still desired.' }
)

Write-Host '==========================================' -ForegroundColor Cyan
Write-Host 'Starter Bootstrap' -ForegroundColor Cyan
Write-Host '==========================================' -ForegroundColor Cyan
Write-Host "App Name: $AppName" -ForegroundColor Yellow
Write-Host "App Slug: $AppSlug" -ForegroundColor Yellow
Write-Host "Database: $DatabaseName" -ForegroundColor Yellow
Write-Host "Admin Email: $AdminEmail" -ForegroundColor Yellow
Write-Host "Admin Password: [configured value]" -ForegroundColor Yellow
Write-Host ''

$updatedAny = $false
foreach ($replacement in $replacements) {
    if (Replace-InFile -Path $replacement.Path -OldValue $replacement.Old -NewValue $replacement.New) {
        $updatedAny = $true
    }
}

if (Test-Path $workspaceOld) {
    if ($Preview) {
        Write-Host "[PREVIEW] $workspaceOld -> $workspaceNew" -ForegroundColor Yellow
    } elseif ($workspaceOld -ne $workspaceNew) {
        Rename-Item -Path $workspaceOld -NewName (Split-Path $workspaceNew -Leaf) -Force
        Write-Host "Renamed workspace file to $(Split-Path $workspaceNew -Leaf)" -ForegroundColor Green
        $updatedAny = $true
    }
}

if (-not $updatedAny) {
    Write-Host 'No placeholder replacements were applied.' -ForegroundColor DarkYellow
} elseif ($Preview) {
    Write-Host ''
    Write-Host 'Preview complete. Re-run without -Preview to apply the changes.' -ForegroundColor Green
} else {
    Write-Host ''
    Write-Host 'Starter placeholders updated.' -ForegroundColor Green
    Write-Host 'Next: review domain-specific schema, controllers, and smoke fixtures before building features.' -ForegroundColor Yellow
}