@echo off
REM MNB ScraperKit V3.0.0 - create a local webhook test event
php "%~dp0..\bin\mnb-scraper" webhook:test --event=scraperkit.test
