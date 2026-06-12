<#
.SYNOPSIS
  Friendly Windows PowerShell wrapper for a single safe crawl.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts/run-crawl.ps1 -Url https://example.com -MaxPages 10 -Depth 1

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts/run-crawl.ps1 -Url https://example.com -Pipeline -Profile academic -JobDir storage/jobs/example

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts/run-crawl.ps1 -Url https://example.com -DryRun
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true, Position=0)]
    [ValidatePattern('^https?://')]
    [string]$Url,

    [int]$MaxPages = 25,
    [int]$Depth = 1,
    [int]$DelayMs = 1000,
    [ValidateSet('json','csv')]
    [string]$Format = 'json',
    [string]$Output = '',
    [string]$JobDir = '',
    [string]$Profile = '',
    [string]$Network = 'direct',
    [ValidateSet('auto','always','off')]
    [string]$BrowserMode = 'auto',
    [switch]$Pipeline,
    [switch]$CommonData,
    [switch]$IncludeHtml,
    [switch]$IgnoreRobots,
    [switch]$NoVerifySsl,
    [switch]$OpenOutput,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$Bin = Join-Path $ProjectRoot 'bin/mnb-scraper'

if ([string]::IsNullOrWhiteSpace($Output)) {
    $safeName = ($Url -replace '^https?://', '' -replace '[^A-Za-z0-9.-]+', '-')
    if ($safeName.Length -gt 60) { $safeName = $safeName.Substring(0, 60) }
    $Output = Join-Path $ProjectRoot ("storage/crawls/$safeName/crawl.$Format")
}

$outputDir = Split-Path -Parent $Output
if ($outputDir -and -not (Test-Path $outputDir)) {
    New-Item -ItemType Directory -Force -Path $outputDir | Out-Null
}

$argsList = @(
    $Bin,
    'crawl',
    $Url,
    "--max-pages=$MaxPages",
    "--depth=$Depth",
    "--delay-ms=$DelayMs",
    "--network=$Network",
    "--browser=$BrowserMode",
    "--format=$Format",
    "--output=$Output"
)

if (-not [string]::IsNullOrWhiteSpace($JobDir)) { $argsList += "--job-dir=$JobDir" }
if (-not [string]::IsNullOrWhiteSpace($Profile)) { $argsList += "--profile=$Profile"; $argsList += "--pipeline-profile=$Profile" }
if ($Pipeline) { $argsList += '--pipeline' }
if ($CommonData) { $argsList += '--common-data' }
if ($IncludeHtml) { $argsList += '--include-html' }
if ($IgnoreRobots) { $argsList += '--ignore-robots' }
if ($NoVerifySsl) { $argsList += '--no-verify-ssl' }

Write-Host 'MNB ScraperKit single crawl' -ForegroundColor Cyan
Write-Host "URL      : $Url"
Write-Host "Output   : $Output"
Write-Host "MaxPages : $MaxPages | Depth: $Depth | DelayMs: $DelayMs | Browser: $BrowserMode"
Write-Host ''

if ($DryRun) {
    Write-Host 'Dry run command:' -ForegroundColor Yellow
    Write-Host ('php ' + (($argsList | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }) -join ' '))
    exit 0
}

& php @argsList
$code = $LASTEXITCODE
if ($code -ne 0) {
    Write-Error "Crawl failed with exit code $code"
    exit $code
}

if ($OpenOutput -and (Test-Path $Output)) {
    Invoke-Item $Output
}
