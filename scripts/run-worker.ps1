# MNB ScraperKit V2.0.0 - run local queue worker loop
param(
    [int]$Sleep = 5,
    [int]$MaxJobs = 10,
    [int]$MaxRuntime = 3600
)
$Root = Split-Path -Parent $PSScriptRoot
php "$Root/bin/mnb-scraper" worker:run --sleep=$Sleep --max-jobs=$MaxJobs --max-runtime=$MaxRuntime
