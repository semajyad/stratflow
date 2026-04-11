param(
    [string]$TargetUrl = "https://stratflow-app-production.up.railway.app",
    [ValidateSet("baseline", "full")]
    [string]$Mode = "baseline",
    [string]$OutputDir = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = Join-Path (Split-Path $PSScriptRoot -Parent) "security-reports"
}

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$reportBase = "zap-$Mode-$timestamp"
$htmlReport = "$reportBase.html"
$jsonReport = "$reportBase.json"
$mdReport = "$reportBase.md"

$cmd = if ($Mode -eq "full") { "zap-full-scan.py" } else { "zap-baseline.py" }
$scanArgs = if ($Mode -eq "full") {
    @("-t", $TargetUrl, "-J", $jsonReport, "-w", $mdReport, "-r", $htmlReport, "-a", "-j", "-T", "30")
} else {
    @("-t", $TargetUrl, "-J", $jsonReport, "-w", $mdReport, "-r", $htmlReport, "-a", "-j", "-m", "10", "-T", "15")
}

Write-Host "Running OWASP ZAP $Mode scan..." -ForegroundColor Cyan
Write-Host ("Target: {0}" -f $TargetUrl)
Write-Host ("Reports: {0}" -f $OutputDir)

docker run --rm `
    -v "${OutputDir}:/zap/wrk" `
    -t ghcr.io/zaproxy/zaproxy:stable `
    $cmd @scanArgs
