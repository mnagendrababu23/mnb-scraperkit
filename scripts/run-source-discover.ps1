<#
.SYNOPSIS
  Discover safer crawl sources such as robots, sitemaps, feeds, and well-known URLs.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true, Position=0)]
    [ValidatePattern('^https?://')]
    [string]$Url,
    [string]$Output = '',
    [switch]$Json,
    [switch]$DryRun
)
$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
$Bin = Join-Path $ProjectRoot 'bin/mnb-scraper'
if ([string]::IsNullOrWhiteSpace($Output)) { $Output = Join-Path $ProjectRoot 'storage/source-discovery/discovery.json' }
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Output) | Out-Null
$argsList = @($Bin, 'source:discover', $Url, "--output=$Output")
if ($Json) { $argsList += '--json' }
if ($DryRun) { Write-Host ('php ' + ($argsList -join ' ')) -ForegroundColor Yellow; exit 0 }
& php @argsList
exit $LASTEXITCODE
