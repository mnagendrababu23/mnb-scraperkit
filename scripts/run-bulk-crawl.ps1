<#
.SYNOPSIS
  Friendly Windows PowerShell wrapper for safe/resumable bulk crawling.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts/run-bulk-crawl.ps1 -UrlFile examples/crawl/sample-urls.txt -JobDir storage/jobs/sample-bulk
#>
[CmdletBinding()]
param(
    [Parameter(Position=0)]
    [string]$UrlFile = 'examples/crawl/sample-urls.txt',
    [string]$JobDir = '',
    [int]$MaxPages = 1,
    [int]$Depth = 0,
    [int]$GapMs = 2000,
    [int]$BatchSize = 5,
    [int]$BatchPause = 30,
    [ValidateSet('json','csv')]
    [string]$Format = 'json',
    [string]$Profile = '',
    [switch]$Pipeline,
    [switch]$Resume = $true,
    [switch]$IgnoreRobots,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$Bin = Join-Path $ProjectRoot 'bin/mnb-scraper'
$ResolvedUrlFile = if ([System.IO.Path]::IsPathRooted($UrlFile)) { $UrlFile } else { Join-Path $ProjectRoot $UrlFile }

if (-not (Test-Path $ResolvedUrlFile)) {
    throw "URL file not found: $ResolvedUrlFile"
}
if ([string]::IsNullOrWhiteSpace($JobDir)) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $JobDir = Join-Path $ProjectRoot "storage/bulk-crawls/bulk-$stamp"
}
New-Item -ItemType Directory -Force -Path $JobDir | Out-Null

$argsList = @(
    $Bin,
    'bulk:crawl',
    $ResolvedUrlFile,
    "--max-pages=$MaxPages",
    "--depth=$Depth",
    "--gap-ms=$GapMs",
    "--batch-size=$BatchSize",
    "--batch-pause=$BatchPause",
    "--format=$Format",
    "--job-dir=$JobDir"
)
if ($Resume) { $argsList += '--resume' }
if ($Pipeline) { $argsList += '--pipeline' }
if (-not [string]::IsNullOrWhiteSpace($Profile)) { $argsList += "--profile=$Profile"; $argsList += "--pipeline-profile=$Profile" }
if ($IgnoreRobots) { $argsList += '--ignore-robots' }

Write-Host 'MNB ScraperKit bulk crawl' -ForegroundColor Cyan
Write-Host "URL file : $ResolvedUrlFile"
Write-Host "Job dir  : $JobDir"
Write-Host "MaxPages : $MaxPages | Depth: $Depth | GapMs: $GapMs | BatchSize: $BatchSize"
Write-Host ''

if ($DryRun) {
    Write-Host 'Dry run command:' -ForegroundColor Yellow
    Write-Host ('php ' + (($argsList | ForEach-Object { if ($_ -match '\s') { '"' + $_ + '"' } else { $_ } }) -join ' '))
    exit 0
}

& php @argsList
exit $LASTEXITCODE
