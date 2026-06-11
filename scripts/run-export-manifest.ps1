param(
    [string]$Target = "storage/jobs"
)
$Root = Split-Path -Parent $PSScriptRoot
php (Join-Path $Root "bin/mnb-scraper") export:manifest $Target
