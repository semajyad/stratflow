param(
    [Parameter(Mandatory = $true)]
    [string]$TargetUrl,

    [string]$Workspace = ("stratflow-" + (Get-Date -Format "yyyyMMdd-HHmmss")),

    [string]$RepoPath = "",

    [string]$ConfigPath = "",

    [string]$OutputPath = "",

    [switch]$PipelineTesting,

    [switch]$Router
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($RepoPath)) {
    $RepoPath = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
}

function Import-DotEnvValue {
    param(
        [Parameter(Mandatory = $true)]
        [string]$FilePath,

        [Parameter(Mandatory = $true)]
        [string]$Key
    )

    if (-not (Test-Path -LiteralPath $FilePath)) {
        return $null
    }

    $line = Get-Content -LiteralPath $FilePath | Where-Object { $_ -match "^${Key}=" } | Select-Object -First 1
    if (-not $line) {
        return $null
    }

    return ($line -replace "^${Key}=", "").Trim()
}

if (-not $env:ANTHROPIC_API_KEY) {
    $dotenvPath = Join-Path (Split-Path $RepoPath -Parent) ".env"
    $dotenvValue = Import-DotEnvValue -FilePath $dotenvPath -Key "ANTHROPIC_API_KEY"
    if ($dotenvValue) {
        $env:ANTHROPIC_API_KEY = $dotenvValue
    }
}

if (-not $env:ANTHROPIC_API_KEY) {
    throw "ANTHROPIC_API_KEY was not found in the current environment or the parent .env file."
}

$arguments = @(
    "-y",
    "@keygraph/shannon@latest",
    "start",
    "--url", $TargetUrl,
    "--repo", $RepoPath,
    "--workspace", $Workspace
)

if ($ConfigPath -ne "") {
    $arguments += @("--config", $ConfigPath)
}

if ($OutputPath -ne "") {
    $arguments += @("--output", $OutputPath)
}

if ($PipelineTesting.IsPresent) {
    $arguments += "--pipeline-testing"
}

if ($Router.IsPresent) {
    $arguments += "--router"
}

Write-Host "Launching Shannon official package..." -ForegroundColor Cyan
Write-Host ("Target:    {0}" -f $TargetUrl)
Write-Host ("Repo:      {0}" -f $RepoPath)
Write-Host ("Workspace: {0}" -f $Workspace)

& npx @arguments
