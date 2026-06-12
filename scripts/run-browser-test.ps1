# MNB ScraperKit V4.1.1 browser fallback diagnostic example
$Root = Split-Path -Parent $PSScriptRoot
php "$Root/bin/mnb-scraper" browser:test @args --browser=auto
