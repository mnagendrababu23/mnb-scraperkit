@echo off
REM MNB ScraperKit V3.7.0 - create a local webhook test event
php "%~dp0..\bin\mnb-scraper" webhook:test --event=scraperkit.test
