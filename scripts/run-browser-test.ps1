# MNB ScraperKit V1.0.3 browser fallback diagnostic example
$Root = Split-Path -Parent $PSScriptRoot
php "$Root/bin/mnb-scraper" browser:test @args --browser=auto
