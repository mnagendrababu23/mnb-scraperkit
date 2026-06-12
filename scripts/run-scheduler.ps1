# MNB ScraperKit V1.0.0 - enqueue due schedules and run queued jobs once
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
php "$Root/bin/mnb-scraper" schedule:run-due
php "$Root/bin/mnb-scraper" worker:run --stop-when-empty
