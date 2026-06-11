# MNB ScraperKit V3.3.0 - optional lightweight JSON API server
param(
    [string]$HostName = "127.0.0.1",
    [int]$Port = 8787
)
php "$PSScriptRoot/../bin/mnb-scraper" api:serve --host=$HostName --port=$Port
