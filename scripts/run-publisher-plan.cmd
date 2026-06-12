@echo off
REM MNB ScraperKit V1.0.1 - generate metadata-only academic publisher crawl plan
php "%~dp0..\bin\mnb-scraper" publisher:plan --max-pages=10 --delay-ms=3500 --output=storage\publisher-crawl-jobs.json %*
