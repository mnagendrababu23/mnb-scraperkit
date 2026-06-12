# MNB ScraperKit V4.1.0 - generate metadata-only academic publisher crawl plan
php "$PSScriptRoot/../bin/mnb-scraper" publisher:plan --max-pages=10 --delay-ms=3500 --output=storage/publisher-crawl-jobs.json @args
