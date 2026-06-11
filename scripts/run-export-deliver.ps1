param(
    [string]$Connector = "local_exports",
    [string]$Target = "storage/jobs"
)
$Root = Split-Path -Parent $PSScriptRoot
php (Join-Path $Root "bin/mnb-scraper") export:deliver $Connector --dir=$Target
