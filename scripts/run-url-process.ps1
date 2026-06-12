<#
.SYNOPSIS
  Process URL lists with retry/backoff and Windows-friendly method ladder.
#>
[CmdletBinding()]
param(
    [Parameter(Position=0)]
    [string]$UrlFile = 'examples/crawl/sample-urls.txt',
    [string]$OutputDir = '',
    [string]$Methods = 'auto,curl,stream,cmd-curl,powershell',
    [int]$MaxAttempts = 3,
    [int]$GapMs = 1000,
    [int]$RetryDelaySeconds = 5,
    [double]$Backoff = 1.5,
    [switch]$Resume = $true,
    [switch]$IncludeHeaders,
    [switch]$SaveBody,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$Bin = Join-Path $ProjectRoot 'bin/mnb-scraper'
$ResolvedUrlFile = if ([System.IO.Path]::IsPathRooted($UrlFile)) { $UrlFile } else { Join-Path $ProjectRoot $UrlFile }
if (-not (Test-Path $ResolvedUrlFile)) { throw "URL file not found: $ResolvedUrlFile" }
if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $OutputDir = Join-Path $ProjectRoot "storage/url-process/process-$stamp"
}
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

$argsList = @(
    $Bin,
    'url:process',
    $ResolvedUrlFile,
    "--methods=$Methods",
    "--max-attempts=$MaxAttempts",
    "--gap-ms=$GapMs",
    "--retry-delay-seconds=$RetryDelaySeconds",
    "--backoff=$Backoff",
    "--output-dir=$OutputDir"
)
if ($Resume) { $argsList += '--resume' }
if ($IncludeHeaders) { $argsList += '--include-headers' }
if ($SaveBody) { $argsList += '--save-body' }

if ($DryRun) {
    Write-Host ('php ' + (($argsList | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }) -join ' ')) -ForegroundColor Yellow
    exit 0
}
& php @argsList
exit $LASTEXITCODE
