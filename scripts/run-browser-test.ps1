# MNB ScraperKit V3.8.0 browser fallback diagnostic example
$Root = Split-Path -Parent $PSScriptRoot
php "$Root/bin/mnb-scraper" browser:test @args --browser=auto
