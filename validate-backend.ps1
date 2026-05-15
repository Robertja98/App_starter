param(
    [string]$BaseUrl = 'http://localhost:8000',
    [int]$StartupTimeoutSeconds = 20
)

$scriptPath = Split-Path -Parent -Path $MyInvocation.MyCommand.Definition
Set-Location $scriptPath

function Test-ServiceAppEndpoint {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Url
    )

    try {
        $response = Invoke-WebRequest -Uri $Url -Method GET -TimeoutSec 3 -UseBasicParsing
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 500
    } catch {
        return $false
    }
}

function Start-ServiceAppServer {
    param(
        [Parameter(Mandatory = $true)]
        [Uri]$Uri,

        [Parameter(Mandatory = $true)]
        [int]$TimeoutSeconds
    )

    $port = if ($Uri.IsDefaultPort) {
        if ($Uri.Scheme -eq 'https') { 443 } else { 80 }
    } else {
        $Uri.Port
    }

    $hostName = if ([string]::IsNullOrWhiteSpace($Uri.Host)) { 'localhost' } else { $Uri.Host }
    $listenAddress = "$hostName`:$port"
    $stdoutPath = Join-Path $scriptPath 'storage\logs\validate-backend-server.out.log'
    $stderrPath = Join-Path $scriptPath 'storage\logs\validate-backend-server.err.log'

    $process = Start-Process -FilePath 'php' `
        -ArgumentList @('-S', $listenAddress, '-t', 'public/') `
        -WorkingDirectory $scriptPath `
        -WindowStyle Hidden `
        -RedirectStandardOutput $stdoutPath `
        -RedirectStandardError $stderrPath `
        -PassThru

    $healthUrl = ([Uri]::new($Uri, '/api/auth/csrf')).AbsoluteUri
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)

    while ((Get-Date) -lt $deadline) {
        if ($process.HasExited) {
            throw "PHP dev server exited early with code $($process.ExitCode). Check $stderrPath for details."
        }

        if (Test-ServiceAppEndpoint -Url $healthUrl) {
            return $process
        }

        Start-Sleep -Milliseconds 250
    }

    try {
        Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue
    } catch {
    }

    throw "Timed out waiting for PHP dev server at $($Uri.AbsoluteUri)."
}

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "Backend Validation Runner" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Target: $BaseUrl" -ForegroundColor Yellow
Write-Host ""

$baseUri = [Uri]$BaseUrl
$healthUrl = ([Uri]::new($baseUri, '/api/auth/csrf')).AbsoluteUri
$startedProcess = $null

if (-not (Test-ServiceAppEndpoint -Url $healthUrl)) {
    Write-Host "No reachable dev server detected. Starting a temporary PHP server for validation..." -ForegroundColor Yellow
    $startedProcess = Start-ServiceAppServer -Uri $baseUri -TimeoutSeconds $StartupTimeoutSeconds
    Write-Host "Temporary PHP server started for validation." -ForegroundColor Green
}

try {
    php .\tests\backend_smoke.php $BaseUrl
    if ($LASTEXITCODE -ne 0) {
        Write-Host "" 
        Write-Host "Backend smoke validation failed." -ForegroundColor Red
        exit $LASTEXITCODE
    }

    Write-Host "" 
    Write-Host "Backend smoke validation passed." -ForegroundColor Green
} finally {
    if ($null -ne $startedProcess -and -not $startedProcess.HasExited) {
        Stop-Process -Id $startedProcess.Id -Force -ErrorAction SilentlyContinue
        Write-Host "Temporary PHP server stopped." -ForegroundColor DarkGray
    }
}