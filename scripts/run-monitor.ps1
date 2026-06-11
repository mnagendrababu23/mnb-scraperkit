# MNB ScraperKit V3.6.0 - local queue/schedule monitoring summary
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
php "$Root/bin/mnb-scraper" monitor:summary
